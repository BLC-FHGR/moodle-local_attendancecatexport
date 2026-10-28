<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_attendancecatexport;

use context_coursecat;
use core_course_category;
use stdClass;

/**
 * Tests for the category attendance report.
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_attendancecatexport\report
 */
final class report_test extends \advanced_testcase {
    /** @var int Course start date used by the tests. */
    protected int $coursestart;

    /**
     * Courses for {@see test_filter_combinations()}: category, visible, start date, end date (null for
     * none) and whether the participant is below the threshold.
     *
     * @var array
     */
    private const FILTER_COMBINATION_COURSES = [
        'PV' => ['parent', 1, '2026-01-10', null, true],
        'PH' => ['parent', 0, '2026-03-01', '2026-07-31', false],
        'CV' => ['child', 1, '2026-09-01', '2027-01-31', false],
        'CH' => ['child', 0, '2026-02-15', '2026-05-31', true],
    ];

    /**
     * Set up.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/attendance/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->coursestart = time() - 30 * DAYSECS;
    }

    /**
     * Points of several attendance instances in one course are summed up, not averaged.
     */
    public function test_multiple_instances_are_aggregated(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'startdate' => $this->coursestart]);
        $student = $generator->create_and_enrol($course, 'student');

        // Instance A: 4 taken sessions, always present (8 / 8 points).
        $atta = $this->create_attendance($course);
        for ($i = 1; $i <= 4; $i++) {
            $sessionid = $this->add_session($atta->id, $this->coursestart + $i * DAYSECS);
            $this->add_log($sessionid, $student->id, $this->get_status($atta->id, 2));
        }
        // A session before the course start date is ignored by mod_attendance.
        $sessionid = $this->add_session($atta->id, $this->coursestart - DAYSECS);
        $this->add_log($sessionid, $student->id, $this->get_status($atta->id, 0));

        // Instance B: 1 taken session absent (0 / 2 points) and 1 session not taken yet.
        $attb = $this->create_attendance($course);
        $sessionid = $this->add_session($attb->id, $this->coursestart + DAYSECS);
        $this->add_log($sessionid, $student->id, $this->get_status($attb->id, 0));
        $this->add_session($attb->id, time() + DAYSECS, 0, false);

        $report = new report(core_course_category::get($category->id));
        $summaries = $report->get_course_summaries($course->id);

        $this->assertCount(1, $summaries);
        $summary = $summaries[$student->id];
        $this->assertEquals(5, $summary->numtakensessions);
        $this->assertEquals(6, $summary->numallsessions);
        $this->assertEqualsWithDelta(8, $summary->points, 0.001);
        $this->assertEqualsWithDelta(10, $summary->takenmaxpoints, 0.001);
        $this->assertEqualsWithDelta(12, $summary->allmaxpoints, 0.001);
        // 8 / 10 = 80 %, whereas averaging the instance percentages would give (100 + 0) / 2 = 50 %.
        $this->assertEqualsWithDelta(0.8, $summary->takenpercentage, 0.0001);
        $this->assertEqualsWithDelta(8 / 12, $summary->allpercentage, 0.0001);

        $this->assert_matches_mod_attendance($course->id, $summaries);

        // The modinfo cached by get_rows() holds a partial course record (without start date).
        get_fast_modinfo(0, 0, true);
        $rows = iterator_to_array($report->get_rows(), false);
        $this->assertCount(1, $rows);
        $this->assertEquals(80.0, $rows[0]['percenttaken']);
        $this->assertEquals(66.67, $rows[0]['percentall']);
        $this->assertEquals(12, $rows[0]['maxpoints']);

        // The course start date must still be applied when the summaries are computed afterwards.
        $this->assertEquals($summaries, $report->get_course_summaries($course->id));
    }

    /**
     * Status sets, hidden and deleted statuses and sessions before the course start are handled like
     * mod_attendance.
     */
    public function test_status_sets_and_hidden_deleted_statuses(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'startdate' => $this->coursestart]);
        $student = $generator->create_and_enrol($course, 'student');
        $att = $this->create_attendance($course);
        $addstatus = fn(string $acronym, float $grade, int $set, int $visible = 1, int $deleted = 0): int =>
            (int) $DB->insert_record('attendance_statuses', [
                'attendanceid' => $att->id,
                'acronym' => $acronym,
                'description' => $acronym,
                'grade' => $grade,
                'setnumber' => $set,
                'visible' => $visible,
                'deleted' => $deleted,
            ]);
        // Status set 0: the default statuses (maximum 2) and a hidden and a deleted status with more points.
        $present = $this->get_status($att->id, 2);
        $hidden = $addstatus('H', 5, 0, 0);
        $deleted = $addstatus('D', 4, 0, 1, 1);
        // Status set 1: maximum 3.
        $addstatus('X', 3, 1);
        $setonelow = $addstatus('Y', 1, 1);

        $this->add_log($this->add_session($att->id, $this->coursestart + DAYSECS), $student->id, $present);
        $this->add_log($this->add_session($att->id, $this->coursestart + 2 * DAYSECS, 0, true, 1), $student->id, $setonelow);
        // Logs with a deleted or hidden status do not count as taken.
        $this->add_log($this->add_session($att->id, $this->coursestart + 3 * DAYSECS), $student->id, $deleted);
        $this->add_log($this->add_session($att->id, $this->coursestart + 4 * DAYSECS), $student->id, $hidden);
        $this->add_session($att->id, time() + DAYSECS, 0, false, 1);
        // Sessions before the course start are ignored.
        $this->add_log($this->add_session($att->id, $this->coursestart - DAYSECS), $student->id, $present);

        $summaries = (new report(core_course_category::get($category->id)))->get_course_summaries($course->id);
        $summary = $summaries[$student->id];
        $this->assertEquals(2, $summary->numtakensessions);
        $this->assertEquals(5, $summary->numallsessions);
        $this->assertEqualsWithDelta(3, $summary->points, 0.001);
        $this->assertEqualsWithDelta(5, $summary->takenmaxpoints, 0.001);
        $this->assertEqualsWithDelta(12, $summary->allmaxpoints, 0.001);
        $this->assert_matches_mod_attendance($course->id, $summaries);
    }

    /**
     * Group sessions only count for members of the group, and only in group mode.
     */
    public function test_group_sessions(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course([
            'category' => $category->id,
            'startdate' => $this->coursestart,
            'groupmode' => SEPARATEGROUPS,
        ]);
        $group1 = $generator->create_group(['courseid' => $course->id]);
        $group2 = $generator->create_group(['courseid' => $course->id]);
        $student1 = $generator->create_and_enrol($course, 'student');
        $student2 = $generator->create_and_enrol($course, 'student');
        $student3 = $generator->create_and_enrol($course, 'student');
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $student1->id]);
        $generator->create_group_member(['groupid' => $group2->id, 'userid' => $student2->id]);

        $att = $this->create_attendance($course, SEPARATEGROUPS);
        $present = $this->get_status($att->id, 2);

        // Session for all participants.
        $sessionid = $this->add_session($att->id, $this->coursestart + DAYSECS);
        foreach ([$student1, $student2, $student3] as $student) {
            $this->add_log($sessionid, $student->id, $present);
        }
        // Taken session of group 1. The log of student 2 (not a member) must be ignored.
        $sessionid = $this->add_session($att->id, $this->coursestart + 2 * DAYSECS, $group1->id);
        $this->add_log($sessionid, $student1->id, $present);
        $this->add_log($sessionid, $student2->id, $present);
        // Session of group 2, not taken yet.
        $this->add_session($att->id, time() + DAYSECS, $group2->id, false);

        // A second instance without group mode ignores group sessions completely.
        $attnogroups = $this->create_attendance($course, NOGROUPS);
        $sessionid = $this->add_session($attnogroups->id, $this->coursestart + DAYSECS, $group1->id);
        $this->add_log($sessionid, $student1->id, $this->get_status($attnogroups->id, 2));

        $report = new report(core_course_category::get($category->id));
        $summaries = $report->get_course_summaries($course->id);
        $this->assertCount(3, $summaries);

        $this->assertEquals(2, $summaries[$student1->id]->numtakensessions);
        $this->assertEquals(2, $summaries[$student1->id]->numallsessions);
        $this->assertEqualsWithDelta(4, $summaries[$student1->id]->allmaxpoints, 0.001);
        $this->assertEqualsWithDelta(1, $summaries[$student1->id]->allpercentage, 0.0001);

        $this->assertEquals(1, $summaries[$student2->id]->numtakensessions);
        $this->assertEquals(2, $summaries[$student2->id]->numallsessions);
        $this->assertEqualsWithDelta(2, $summaries[$student2->id]->points, 0.001);
        $this->assertEqualsWithDelta(1, $summaries[$student2->id]->takenpercentage, 0.0001);
        $this->assertEqualsWithDelta(0.5, $summaries[$student2->id]->allpercentage, 0.0001);

        $this->assertEquals(1, $summaries[$student3->id]->numtakensessions);
        $this->assertEquals(1, $summaries[$student3->id]->numallsessions);

        $this->assert_matches_mod_attendance($course->id, $summaries);
    }

    /**
     * Subcategories are included on request and courses without attendance are skipped.
     */
    public function test_subcategories(): void {
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category(['name' => 'Parent']);
        $child = $generator->create_category(['name' => 'Child', 'parent' => $parent->id]);
        $parentcourse = $generator->create_course(['category' => $parent->id, 'shortname' => 'P1']);
        $childcourse = $generator->create_course(['category' => $child->id, 'shortname' => 'C1']);
        $noattendance = $generator->create_course(['category' => $child->id, 'shortname' => 'C2']);
        foreach ([$parentcourse, $childcourse] as $course) {
            $generator->create_and_enrol($course, 'student');
            $this->create_attendance($course);
        }
        $generator->create_and_enrol($noattendance, 'student');

        $category = core_course_category::get($parent->id);

        $rows = iterator_to_array((new report($category, true))->get_rows(), false);
        $shortnames = array_column($rows, 'courseshortname');
        sort($shortnames);
        $this->assertEquals(['C1', 'P1'], $shortnames);
        $categories = array_column($rows, 'category', 'courseshortname');
        $this->assertEquals('Parent', $categories['P1']);
        $this->assertEquals('Parent / Child', $categories['C1']);

        $rows = iterator_to_array((new report($category, false))->get_rows(), false);
        $this->assertEquals(['P1'], array_column($rows, 'courseshortname'));
    }

    /**
     * Only participants listed by mod_attendance with an active enrolment and account are exported.
     */
    public function test_only_listed_active_participants(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $student = $generator->create_and_enrol($course, 'student');
        // Not listed: no mod/attendance:canbelisted.
        $generator->create_and_enrol($course, 'editingteacher');
        $nocapability = $generator->create_user();
        $generator->enrol_user($nocapability->id, $course->id, $generator->create_role(['shortname' => 'nolist']));
        // Not active: suspended enrolment, suspended account, expired and future enrolment.
        $suspendedenrolment = $generator->create_user();
        $generator->enrol_user($suspendedenrolment->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $suspendedaccount = $generator->create_user(['suspended' => 1]);
        $generator->enrol_user($suspendedaccount->id, $course->id, 'student');
        $expired = $generator->create_user();
        $generator->enrol_user($expired->id, $course->id, 'student', 'manual', time() - 10 * DAYSECS, time() - DAYSECS);
        $future = $generator->create_user();
        $generator->enrol_user($future->id, $course->id, 'student', 'manual', time() + DAYSECS);
        $this->create_attendance($course);

        $report = new report(core_course_category::get($category->id));
        $this->assertEquals([$student->id], array_keys($report->get_course_summaries($course->id)));
    }

    /**
     * Instances in which a participant is not listed do not count for that participant.
     */
    public function test_participant_listed_in_one_instance_only(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'startdate' => $this->coursestart]);
        $student = $generator->create_and_enrol($course, 'student');

        $listed = $this->create_attendance($course);
        $sessionid = $this->add_session($listed->id, $this->coursestart + DAYSECS);
        $this->add_log($sessionid, $student->id, $this->get_status($listed->id, 2));

        $notlisted = $this->create_attendance($course);
        $sessionid = $this->add_session($notlisted->id, $this->coursestart + DAYSECS);
        $this->add_log($sessionid, $student->id, $this->get_status($notlisted->id, 0));
        assign_capability(
            'mod/attendance:canbelisted',
            CAP_PROHIBIT,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            \context_module::instance($notlisted->cmid)->id
        );

        $summary = (new report(core_course_category::get($category->id)))->get_course_summaries($course->id)[$student->id];
        $this->assertEquals(1, $summary->numtakensessions);
        $this->assertEquals(1, $summary->numallsessions);
        $this->assertEqualsWithDelta(2, $summary->points, 0.001);
        $this->assertEqualsWithDelta(1, $summary->allpercentage, 0.0001);
    }

    /**
     * The threshold is evaluated against the selected basis.
     */
    public function test_threshold_and_basis(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'startdate' => $this->coursestart]);
        $student = $generator->create_and_enrol($course, 'student');
        $att = $this->create_attendance($course);
        // 3 of 4 sessions taken, always present: 100 % of taken sessions, 75 % of all sessions.
        for ($i = 1; $i <= 3; $i++) {
            $sessionid = $this->add_session($att->id, $this->coursestart + $i * DAYSECS);
            $this->add_log($sessionid, $student->id, $this->get_status($att->id, 2));
        }
        $this->add_session($att->id, time() + DAYSECS, 0, false);

        $category = core_course_category::get($category->id);

        $row = iterator_to_array((new report($category, true, 80, report::BASIS_ALL))->get_rows(), false)[0];
        $this->assertEquals(75.0, $row['percentall']);
        $this->assertEquals(100.0, $row['percenttaken']);
        $this->assertEquals(8, $row['maxpoints']);
        $this->assertEquals(get_string('no'), $row['thresholdmet']);

        $row = iterator_to_array((new report($category, true, 80, report::BASIS_TAKEN))->get_rows(), false)[0];
        $this->assertEquals(6, $row['maxpoints']);
        $this->assertEquals(get_string('yes'), $row['thresholdmet']);

        $row = iterator_to_array((new report($category, true, 75, report::BASIS_ALL))->get_rows(), false)[0];
        $this->assertEquals(get_string('yes'), $row['thresholdmet']);

        $this->assertEquals(array_keys((new report($category))->get_columns()), array_keys($row));
    }

    /**
     * Data provider for {@see test_threshold_boundaries()}.
     *
     * Grades of the default status set: present 2, late 1, absent 0 (maximum 2 per session).
     *
     * @return array Grades of the taken sessions, number of open sessions, basis, threshold, expected
     *               "below threshold".
     */
    public static function threshold_boundaries_provider(): array {
        return [
            'taken: exactly on threshold' => [[2, 2, 2, 2, 0], 1, report::BASIS_TAKEN, 80, false],
            'taken: just above threshold' => [[2, 2, 2, 2, 0], 1, report::BASIS_TAKEN, 79.9999, false],
            'taken: just below threshold' => [[2, 2, 2, 2, 0], 1, report::BASIS_TAKEN, 80.0001, true],
            'taken: open sessions ignored' => [[2, 2, 2, 2], 1, report::BASIS_TAKEN, 100, false],
            'all: exactly on threshold' => [[2, 2, 2, 2], 1, report::BASIS_ALL, 80, false],
            'all: just above threshold' => [[2, 2, 2, 2], 1, report::BASIS_ALL, 79.9999, false],
            'all: just below threshold' => [[2, 2, 2, 2], 1, report::BASIS_ALL, 80.0001, true],
            'taken: 66.666 % (exported as 66.67) is below 66.67' => [[2, 2, 0], 0, report::BASIS_TAKEN, 66.67, true],
            'taken: 66.666 % meets 66.66' => [[2, 2, 0], 0, report::BASIS_TAKEN, 66.66, false],
            'all: 83.333 % (exported as 83.33) meets 83.333' => [[2, 2, 1], 0, report::BASIS_ALL, 83.333, false],
        ];
    }

    /**
     * The threshold is compared on unrounded values of the selected basis.
     *
     * @dataProvider threshold_boundaries_provider
     * @param int[] $grades Grades of the taken sessions.
     * @param int $numopen Number of sessions not taken yet.
     * @param string $basis The percentage basis.
     * @param float $threshold The threshold in percent.
     * @param bool $expectedbelow Whether the row is expected below the threshold.
     */
    public function test_threshold_boundaries(
        array $grades,
        int $numopen,
        string $basis,
        float $threshold,
        bool $expectedbelow
    ): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'startdate' => $this->coursestart]);
        $student = $generator->create_and_enrol($course, 'student');
        $att = $this->create_attendance($course);
        foreach ($grades as $i => $grade) {
            $sessionid = $this->add_session($att->id, $this->coursestart + ($i + 1) * DAYSECS);
            $this->add_log($sessionid, $student->id, $this->get_status($att->id, $grade));
        }
        for ($i = 1; $i <= $numopen; $i++) {
            $this->add_session($att->id, time() + $i * DAYSECS, 0, false);
        }
        $category = core_course_category::get($category->id);

        $report = new report($category, true, $threshold, $basis);
        $summary = $report->get_course_summaries($course->id)[$student->id];
        $this->assertSame($expectedbelow, $report->is_below_threshold($summary));
        $this->assertSame(!$expectedbelow, $report->meets_threshold($summary));
        $rows = iterator_to_array($report->get_rows(), false);
        $this->assertEquals(get_string($expectedbelow ? 'no' : 'yes'), $rows[0]['thresholdmet']);

        $report = new report($category, true, $threshold, $basis, ['belowthresholdonly' => true]);
        $rows = iterator_to_array($report->get_rows(), false);
        $this->assertCount($expectedbelow ? 1 : 0, $rows);
    }

    /**
     * Participants without taken sessions are only below the threshold with the basis "all sessions" if
     * sessions relevant to them have already taken place.
     */
    public function test_below_threshold_without_taken_sessions(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();

        // Course P: a past group session for group 1 and a future session for all participants.
        $coursep = $generator->create_course([
            'category' => $category->id,
            'startdate' => $this->coursestart,
            'groupmode' => SEPARATEGROUPS,
            'shortname' => 'P',
        ]);
        $group1 = $generator->create_group(['courseid' => $coursep->id]);
        $nolog = $generator->create_and_enrol($coursep, 'student', ['lastname' => 'A']);
        $present = $generator->create_and_enrol($coursep, 'student', ['lastname' => 'B']);
        $nogroup = $generator->create_and_enrol($coursep, 'student', ['lastname' => 'C']);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $nolog->id]);
        $generator->create_group_member(['groupid' => $group1->id, 'userid' => $present->id]);
        $att = $this->create_attendance($coursep, SEPARATEGROUPS);
        $sessionid = $this->add_session($att->id, $this->coursestart + DAYSECS, $group1->id);
        $this->add_log($sessionid, $present->id, $this->get_status($att->id, 2));
        $this->add_session($att->id, time() + DAYSECS, 0, false);

        // Course F: only a future session.
        $coursef = $generator->create_course([
            'category' => $category->id,
            'startdate' => $this->coursestart,
            'shortname' => 'F',
        ]);
        $future = $generator->create_and_enrol($coursef, 'student');
        $attf = $this->create_attendance($coursef);
        $this->add_session($attf->id, time() + DAYSECS, 0, false);

        $category = core_course_category::get($category->id);

        $summaries = (new report($category))->get_course_summaries($coursep->id);
        $this->assertEquals(1, $summaries[$nolog->id]->numpastsessions);
        $this->assertEquals(0, $summaries[$nogroup->id]->numpastsessions);
        $this->assertEquals(0, (new report($category))->get_course_summaries($coursef->id)[$future->id]->numpastsessions);

        // Basis "all sessions", threshold 50 %: only the participant with a past session but no log is below
        // (the present participant has 2 of 4 points, the others no past sessions).
        $report = new report($category, true, 50, report::BASIS_ALL, ['belowthresholdonly' => true]);
        $rows = iterator_to_array($report->get_rows(), false);
        $this->assertCount(1, $rows);
        $this->assertEquals('P', $rows[0]['courseshortname']);
        $this->assertEquals($nolog->email, $rows[0]['email']);

        // Without the filter, the threshold column still reports "No" for participants without points.
        $rows = iterator_to_array((new report($category, true, 50, report::BASIS_ALL))->get_rows(), false);
        $thresholdmet = array_column($rows, 'thresholdmet', 'email');
        $this->assertEquals(get_string('no'), $thresholdmet[$nogroup->email]);
        $this->assertEquals(get_string('yes'), $thresholdmet[$present->email]);

        // Basis "taken sessions": participants without taken sessions are never below the threshold.
        $report = new report($category, true, 50, report::BASIS_TAKEN, ['belowthresholdonly' => true]);
        $this->assertCount(0, iterator_to_array($report->get_rows(), false));
    }

    /**
     * Rows without maximum points (no sessions, or only statuses without points) have an undefined
     * percentage: the threshold is not met, but they are not below the threshold either.
     */
    public function test_below_threshold_without_maximum_points(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();

        // Course with one taken session whose status set only contains a status without points.
        $zero = $generator->create_course(['category' => $category->id, 'startdate' => $this->coursestart]);
        $student = $generator->create_and_enrol($zero, 'student');
        $att = $this->create_attendance($zero);
        $statusid = $DB->insert_record('attendance_statuses', [
            'attendanceid' => $att->id,
            'acronym' => 'N',
            'description' => 'No points',
            'grade' => 0,
            'setnumber' => 1,
        ]);
        $sessionid = $this->add_session($att->id, $this->coursestart + DAYSECS, 0, true, 1);
        $this->add_log($sessionid, $student->id, $statusid);

        // Course with an attendance activity without sessions.
        $empty = $generator->create_course(['category' => $category->id, 'startdate' => $this->coursestart]);
        $generator->create_and_enrol($empty, 'student');
        $this->create_attendance($empty);

        $category = core_course_category::get($category->id);
        $summaries = (new report($category))->get_course_summaries($zero->id);
        $this->assertEquals(1, $summaries[$student->id]->numtakensessions);
        $this->assertEqualsWithDelta(0, $summaries[$student->id]->allmaxpoints, 0.001);
        $this->assert_matches_mod_attendance($zero->id, $summaries);
        $this->assert_matches_mod_attendance($empty->id, (new report($category))->get_course_summaries($empty->id));

        foreach ([report::BASIS_ALL, report::BASIS_TAKEN] as $basis) {
            $rows = iterator_to_array((new report($category, true, 80, $basis))->get_rows(), false);
            $this->assertCount(2, $rows);
            foreach ($rows as $row) {
                $this->assertEquals(0, $row['maxpoints']);
                $this->assertEquals(0, $row['percenttaken']);
                $this->assertEquals(0, $row['percentall']);
                $this->assertEquals(get_string('no'), $row['thresholdmet']);
            }
            $report = new report($category, true, 80, $basis, ['belowthresholdonly' => true]);
            $this->assertCount(0, iterator_to_array($report->get_rows(), false));
        }
    }

    /**
     * The filter "below threshold only" applies per course and participant.
     */
    public function test_below_threshold_per_row(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $student = $generator->create_user();
        foreach (['BELOW' => 0, 'ABOVE' => 2] as $shortname => $grade) {
            $course = $generator->create_course([
                'category' => $category->id,
                'startdate' => $this->coursestart,
                'shortname' => $shortname,
            ]);
            $generator->enrol_user($student->id, $course->id, 'student');
            $att = $this->create_attendance($course);
            $sessionid = $this->add_session($att->id, $this->coursestart + DAYSECS);
            $this->add_log($sessionid, $student->id, $this->get_status($att->id, $grade));
        }

        $report = new report(core_course_category::get($category->id), true, 80, report::BASIS_ALL, [
            'belowthresholdonly' => true,
        ]);
        $rows = iterator_to_array($report->get_rows(), false);
        $this->assertEquals(['BELOW'], array_column($rows, 'courseshortname'));
    }

    /**
     * Data provider for {@see test_course_filters()}.
     *
     * Courses: A (2026-01-10 to 2026-06-30), B (2026-03-01, no end date), C (2026-09-01 to 2027-01-31)
     * and the hidden course H (2026-03-01 to 2026-07-31).
     *
     * @return array
     */
    public static function course_filters_provider(): array {
        return [
            'no filters, hidden courses excluded' => [[], ['A', 'B', 'C']],
            'hidden courses included' => [['includehidden' => true], ['A', 'B', 'C', 'H']],
            'start from' => [['startfrom' => '2026-02-01'], ['B', 'C']],
            'start to, bound inclusive' => [['startto' => '2026-03-01'], ['A', 'B']],
            'start from and to' => [['startfrom' => '2026-02-01', 'startto' => '2026-06-30'], ['B']],
            'start from and to, hidden included' => [
                ['startfrom' => '2026-02-01', 'startto' => '2026-06-30', 'includehidden' => true],
                ['B', 'H'],
            ],
            'end from, no end date included' => [['endfrom' => '2026-07-01'], ['B', 'C']],
            'end to, no end date included' => [['endto' => '2026-06-30'], ['A', 'B']],
            'end from and to, no end date included' => [['endfrom' => '2026-07-01', 'endto' => '2026-12-31'], ['B']],
            'end from and to, hidden included' => [
                ['endfrom' => '2026-07-01', 'endto' => '2026-12-31', 'includehidden' => true],
                ['B', 'H'],
            ],
            'start and end combined' => [
                ['startfrom' => '2026-01-01', 'startto' => '2026-06-30', 'endto' => '2026-06-30'],
                ['A', 'B'],
            ],
            'all filters' => [
                [
                    'startfrom' => '2026-02-01',
                    'startto' => '2026-12-31',
                    'endfrom' => '2026-07-01',
                    'endto' => '2027-12-31',
                    'includehidden' => true,
                ],
                ['B', 'C', 'H'],
            ],
            'no match' => [['startfrom' => '2027-01-01'], []],
        ];
    }

    /**
     * Course filters on start date, end date and visibility are applied before attendance data is loaded.
     *
     * @dataProvider course_filters_provider
     * @param array $filters Filters with dates as 'Y-m-d' (UTC).
     * @param string[] $expected Expected course short names.
     */
    public function test_course_filters(array $filters, array $expected): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $courses = [
            'A' => ['startdate' => '2026-01-10', 'enddate' => '2026-06-30', 'visible' => 1],
            'B' => ['startdate' => '2026-03-01', 'enddate' => null, 'visible' => 1],
            'C' => ['startdate' => '2026-09-01', 'enddate' => '2027-01-31', 'visible' => 1],
            'H' => ['startdate' => '2026-03-01', 'enddate' => '2026-07-31', 'visible' => 0],
        ];
        $courseids = [];
        foreach ($courses as $shortname => $data) {
            $course = $generator->create_course([
                'category' => $category->id,
                'shortname' => $shortname,
                'startdate' => self::utc_timestamp($data['startdate']),
                'enddate' => $data['enddate'] ? self::utc_timestamp($data['enddate']) : 0,
                'visible' => $data['visible'],
            ]);
            $generator->create_and_enrol($course, 'student');
            $this->create_attendance($course);
            $courseids[$shortname] = $course->id;
        }
        $this->assertEquals(0, $DB->get_field('course', 'enddate', ['id' => $courseids['B']]));

        foreach ($filters as $key => $value) {
            if (is_string($value)) {
                $filters[$key] = self::utc_timestamp($value);
            }
        }

        // Record the courses for which attendance data is loaded.
        $report = new class (core_course_category::get($category->id), true, 80, report::BASIS_ALL, $filters) extends report {
            /** @var int[] Ids of the courses for which summaries were computed. */
            public array $loadedcourseids = [];

            /**
             * Records the course id and computes the summaries.
             *
             * @param int|\core_course_list_element $course The course.
             * @return stdClass[]
             */
            public function get_course_summaries(int|\core_course_list_element $course): array {
                $this->loadedcourseids[] = is_int($course) ? $course : (int) $course->id;
                return parent::get_course_summaries($course);
            }
        };

        $shortnames = array_column(iterator_to_array($report->get_rows(), false), 'courseshortname');
        sort($shortnames);
        $this->assertEquals($expected, $shortnames);

        $expectedids = array_map(fn($shortname) => $courseids[$shortname], $expected);
        $loadedids = $report->loadedcourseids;
        sort($expectedids);
        sort($loadedids);
        $this->assertEquals($expectedids, $loadedids);
    }

    /**
     * Data provider for {@see test_filter_combinations()}: all combinations of subcategories, hidden
     * courses, date filter and "below threshold only".
     *
     * @return array
     */
    public static function filter_combinations_provider(): array {
        $datefilters = ['none' => [], 'start from' => ['startfrom' => '2026-02-01'], 'end to' => ['endto' => '2026-06-30']];
        $cases = [];
        foreach ([false, true] as $subcategories) {
            foreach ([false, true] as $hidden) {
                foreach ($datefilters as $datename => $datefilter) {
                    foreach ([false, true] as $belowonly) {
                        $expected = [];
                        foreach (self::FILTER_COMBINATION_COURSES as $shortname => [$category, $visible, $start, $end, $below]) {
                            $startfrom = $datefilter['startfrom'] ?? null;
                            $endto = $datefilter['endto'] ?? null;
                            if (
                                (!$subcategories && $category === 'child') ||
                                (!$hidden && !$visible) ||
                                ($startfrom && $start < $startfrom) ||
                                ($endto && $end && $end > $endto) ||
                                ($belowonly && !$below)
                            ) {
                                continue;
                            }
                            $expected[] = $shortname;
                        }
                        sort($expected);
                        $name = sprintf(
                            'subcategories %s, hidden %s, date %s, below only %s',
                            json_encode($subcategories),
                            json_encode($hidden),
                            $datename,
                            json_encode($belowonly)
                        );
                        $cases[$name] = [$subcategories, $hidden, $datefilter, $belowonly, $expected];
                    }
                }
            }
        }
        return $cases;
    }

    /**
     * All filters work together.
     *
     * @dataProvider filter_combinations_provider
     * @param bool $subcategories Whether subcategories are included.
     * @param bool $hidden Whether hidden courses are included.
     * @param array $datefilter Date filters with dates as 'Y-m-d' (UTC).
     * @param bool $belowonly Whether only rows below the threshold are exported.
     * @param string[] $expected Expected course short names, sorted.
     */
    public function test_filter_combinations(
        bool $subcategories,
        bool $hidden,
        array $datefilter,
        bool $belowonly,
        array $expected
    ): void {
        $generator = $this->getDataGenerator();
        $categories = ['parent' => $generator->create_category()];
        $categories['child'] = $generator->create_category(['parent' => $categories['parent']->id]);
        foreach (self::FILTER_COMBINATION_COURSES as $shortname => [$category, $visible, $start, $end, $below]) {
            $course = $generator->create_course([
                'category' => $categories[$category]->id,
                'shortname' => $shortname,
                'visible' => $visible,
                'startdate' => self::utc_timestamp($start),
                'enddate' => $end ? self::utc_timestamp($end) : 0,
            ]);
            $student = $generator->create_and_enrol($course, 'student');
            $att = $this->create_attendance($course);
            $sessionid = $this->add_session($att->id, self::utc_timestamp($start) + DAYSECS);
            $this->add_log($sessionid, $student->id, $this->get_status($att->id, $below ? 0 : 2));
        }

        $filters = array_map(fn($date) => self::utc_timestamp($date), $datefilter);
        $filters['includehidden'] = $hidden;
        $filters['belowthresholdonly'] = $belowonly;
        $report = new report(
            core_course_category::get($categories['parent']->id),
            $subcategories,
            80,
            report::BASIS_ALL,
            $filters
        );
        $shortnames = array_column(iterator_to_array($report->get_rows(), false), 'courseshortname');
        sort($shortnames);
        $this->assertEquals($expected, $shortnames);
    }

    /**
     * Unknown filters are rejected.
     */
    public function test_unknown_filter(): void {
        $category = core_course_category::get($this->getDataGenerator()->create_category()->id);
        $this->expectException(\coding_exception::class);
        new report($category, true, 80, report::BASIS_ALL, ['visible' => 1]);
    }

    /**
     * The form rejects "from" dates after "to" dates.
     *
     * @covers \local_attendancecatexport\form\export_form
     */
    public function test_form_validation(): void {
        $category = $this->getDataGenerator()->create_category();
        $url = new \moodle_url('/local/attendancecatexport/export.php', ['categoryid' => $category->id]);
        $form = new form\export_form($url, ['categoryid' => $category->id]);
        $data = [
            'categoryid' => $category->id,
            'includesubcategories' => 1,
            'threshold' => 80,
            'basis' => report::BASIS_ALL,
            'dataformat' => 'csv',
            'startfrom' => 0,
            'startto' => 0,
            'endfrom' => 0,
            'endto' => 0,
            'includehidden' => 0,
        ];
        $day = self::utc_timestamp('2026-03-01');

        $this->assertEquals([], $form->validation($data, []));
        $this->assertEquals([], $form->validation(['startfrom' => $day + DAYSECS, 'endto' => $day] + $data, []));
        $this->assertEquals([], $form->validation(['startfrom' => $day, 'startto' => $day] + $data, []));

        $errors = $form->validation(['startfrom' => $day + DAYSECS, 'startto' => $day] + $data, []);
        $this->assertEquals(['startto'], array_keys($errors));

        $errors = $form->validation(['endfrom' => $day + DAYSECS, 'endto' => $day] + $data, []);
        $this->assertEquals(['endto'], array_keys($errors));
    }

    /**
     * "To" dates of the form include the whole day in the user's timezone, also on DST changes.
     *
     * @covers \local_attendancecatexport\form\export_form
     */
    public function test_form_filters(): void {
        $this->setTimezone('Europe/Zurich');

        // 2026-03-29 has 23 hours in Europe/Zurich.
        $from = make_timestamp(2026, 3, 1);
        $to = make_timestamp(2026, 3, 29);
        $filters = form\export_form::get_filters((object) [
            'startfrom' => $from,
            'startto' => $to,
            'endfrom' => 0,
            'endto' => 0,
            'includehidden' => 1,
        ]);

        $this->assertEquals([
            'startfrom' => $from,
            'startto' => make_timestamp(2026, 3, 30) - 1,
            'endfrom' => 0,
            'endto' => 0,
            'includehidden' => true,
            'belowthresholdonly' => false,
        ], $filters);
        $this->assertNotEquals($to + DAYSECS - 1, $filters['startto']);
    }

    /**
     * Export and navigation link require mod/attendance:viewsummaryreports in the category context.
     */
    public function test_capability(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/attendancecatexport/lib.php');

        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $context = context_coursecat::instance($category->id);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $manager = $generator->create_user();
        $generator->role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, $context->id);

        $this->setUser($manager);
        $this->assertTrue(report::can_export($context));
        report::require_export_capability($context);
        $node = \navigation_node::create('category');
        local_attendancecatexport_extend_navigation_category_settings($node, $context);
        $exportnode = $node->get('local_attendancecatexport', \navigation_node::TYPE_SETTING);
        $this->assertNotEmpty($exportnode);
        $this->assertTrue($exportnode->forceintomoremenu);

        $this->setUser($teacher);
        $this->assertFalse(report::can_export($context));
        $node = \navigation_node::create('category');
        local_attendancecatexport_extend_navigation_category_settings($node, $context);
        $this->assertFalse($node->get('local_attendancecatexport', \navigation_node::TYPE_SETTING));
        $this->expectException(\required_capability_exception::class);
        report::require_export_capability($context);
    }

    /**
     * Courses in which the capability is prohibited or prevented by an override are not exported.
     */
    public function test_capability_overridden_in_subcategory_and_course(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $child = $generator->create_category(['parent' => $parent->id]);
        $courses = [
            'PARENT' => $generator->create_course(['category' => $parent->id, 'shortname' => 'PARENT']),
            'PREVENTED' => $generator->create_course(['category' => $parent->id, 'shortname' => 'PREVENTED']),
            'CHILD' => $generator->create_course(['category' => $child->id, 'shortname' => 'CHILD']),
        ];
        foreach ($courses as $course) {
            $generator->create_and_enrol($course, 'student');
            $this->create_attendance($course);
        }

        $managerroleid = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        $manager = $generator->create_user();
        $generator->role_assign($managerroleid, $manager->id, context_coursecat::instance($parent->id)->id);
        assign_capability(report::CAPABILITY, CAP_PROHIBIT, $managerroleid, context_coursecat::instance($child->id)->id);
        assign_capability(
            report::CAPABILITY,
            CAP_PREVENT,
            $managerroleid,
            \context_course::instance($courses['PREVENTED']->id)->id
        );

        $this->setUser($manager);
        $report = new report(core_course_category::get($parent->id));
        $this->assertEquals(['PARENT'], array_values(array_map(fn($course) => $course->shortname, $report->get_courses())));
        $this->assertEquals(['PARENT'], array_column(iterator_to_array($report->get_rows(), false), 'courseshortname'));
    }

    /**
     * The report exported event contains the export options.
     *
     * @covers \local_attendancecatexport\event\report_exported
     */
    public function test_report_exported_event(): void {
        $category = $this->getDataGenerator()->create_category();
        $context = context_coursecat::instance($category->id);

        $sink = $this->redirectEvents();
        event\report_exported::create([
            'context' => $context,
            'other' => [
                'includesubcategories' => true,
                'threshold' => 80.0,
                'basis' => report::BASIS_ALL,
                'dataformat' => 'csv',
                'startfrom' => 1767225600,
                'startto' => 0,
                'endfrom' => 0,
                'endto' => 1798761599,
                'includehidden' => false,
                'belowthresholdonly' => true,
            ],
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(event\report_exported::class, $event);
        $this->assertEquals($context, $event->get_context());
        $this->assertEquals('csv', $event->other['dataformat']);
        $this->assertEquals(1767225600, $event->other['startfrom']);
        $this->assertEquals(1798761599, $event->other['endto']);
        $this->assertFalse($event->other['includehidden']);
        $this->assertTrue($event->other['belowthresholdonly']);
        $this->assertEquals(
            new \moodle_url('/local/attendancecatexport/export.php', ['categoryid' => $category->id]),
            $event->get_url()
        );
    }

    /**
     * Asserts that the aggregated summaries equal the sum of the mod_attendance_summary values of all instances.
     *
     * @param int $courseid The course id.
     * @param stdClass[] $summaries Summaries returned by report::get_course_summaries().
     */
    protected function assert_matches_mod_attendance(int $courseid, array $summaries): void {
        $instances = get_fast_modinfo($courseid)->get_instances_of('attendance');
        foreach ($summaries as $userid => $summary) {
            $expected = ['numtakensessions' => 0, 'numallsessions' => 0, 'points' => 0, 'takenmax' => 0, 'allmax' => 0];
            foreach ($instances as $cm) {
                $usersummary = (new \mod_attendance_summary($cm->instance))->get_all_sessions_summary_for($userid);
                $expected['numtakensessions'] += $usersummary->numtakensessions;
                $expected['numallsessions'] += $usersummary->numallsessions;
                $expected['points'] += $usersummary->takensessionspoints;
                $expected['takenmax'] += $usersummary->takensessionsmaxpoints;
                $expected['allmax'] += $usersummary->allsessionsmaxpoints;
            }
            $this->assertEquals($expected['numtakensessions'], $summary->numtakensessions);
            $this->assertEquals($expected['numallsessions'], $summary->numallsessions);
            $this->assertEqualsWithDelta($expected['points'], $summary->points, 0.001);
            $this->assertEqualsWithDelta($expected['takenmax'], $summary->takenmaxpoints, 0.001);
            $this->assertEqualsWithDelta($expected['allmax'], $summary->allmaxpoints, 0.001);
        }
    }

    /**
     * Converts a date in the format 'Y-m-d' (UTC midnight) to a timestamp.
     *
     * @param string $date The date.
     * @return int
     */
    protected static function utc_timestamp(string $date): int {
        return (new \DateTimeImmutable($date, new \DateTimeZone('UTC')))->getTimestamp();
    }

    /**
     * Creates an attendance instance.
     *
     * @param stdClass $course The course.
     * @param int $groupmode The group mode of the activity.
     * @return stdClass The attendance record.
     */
    protected function create_attendance(stdClass $course, int $groupmode = NOGROUPS): stdClass {
        return $this->getDataGenerator()->create_module('attendance', ['course' => $course->id, 'groupmode' => $groupmode]);
    }

    /**
     * Inserts an attendance session.
     *
     * @param int $attendanceid The attendance instance id.
     * @param int $sessdate The session date.
     * @param int $groupid The group id, 0 for all participants.
     * @param bool $taken Whether the session has been taken.
     * @param int $statusset The status set of the session.
     * @return int The session id.
     */
    protected function add_session(
        int $attendanceid,
        int $sessdate,
        int $groupid = 0,
        bool $taken = true,
        int $statusset = 0
    ): int {
        global $DB;
        return $DB->insert_record('attendance_sessions', [
            'attendanceid' => $attendanceid,
            'groupid' => $groupid,
            'sessdate' => $sessdate,
            'duration' => HOURSECS,
            'lasttaken' => $taken ? $sessdate : 0,
            'timemodified' => time(),
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'statusset' => $statusset,
            'calendarevent' => 0,
        ]);
    }

    /**
     * Inserts an attendance log entry.
     *
     * @param int $sessionid The session id.
     * @param int $userid The user id.
     * @param int $statusid The status id.
     */
    protected function add_log(int $sessionid, int $userid, int $statusid): void {
        global $DB;
        $DB->insert_record('attendance_log', [
            'sessionid' => $sessionid,
            'studentid' => $userid,
            'statusid' => $statusid,
            'timetaken' => time(),
            'takenby' => 0,
        ]);
    }

    /**
     * Returns the id of a default status (status set 0) with the given grade.
     *
     * The default statuses are Present (2), Absent (0), Late (1) and Excused (1).
     *
     * @param int $attendanceid The attendance instance id.
     * @param int $grade The grade of the status.
     * @return int The status id.
     */
    protected function get_status(int $attendanceid, int $grade): int {
        global $DB;
        $statuses = $DB->get_records(
            'attendance_statuses',
            ['attendanceid' => $attendanceid, 'setnumber' => 0, 'grade' => $grade, 'deleted' => 0],
            'id',
            'id'
        );
        return (int) array_key_first($statuses);
    }
}
