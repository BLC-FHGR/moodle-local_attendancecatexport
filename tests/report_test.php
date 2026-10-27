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

        $rows = iterator_to_array($report->get_rows(), false);
        $this->assertCount(1, $rows);
        $this->assertEquals(80.0, $rows[0]['percenttaken']);
        $this->assertEquals(66.67, $rows[0]['percentall']);
        $this->assertEquals(12, $rows[0]['maxpoints']);
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
     * Only participants listed by mod_attendance with an active enrolment are exported.
     */
    public function test_only_listed_active_participants(): void {
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $student = $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'editingteacher');
        $suspended = $generator->create_user();
        $generator->enrol_user($suspended->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $this->create_attendance($course);

        $report = new report(core_course_category::get($category->id));
        $this->assertEquals([$student->id], array_keys($report->get_course_summaries($course->id)));
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
        $this->assertNotEmpty($node->get('local_attendancecatexport', \navigation_node::TYPE_SETTING));

        $this->setUser($teacher);
        $this->assertFalse(report::can_export($context));
        $node = \navigation_node::create('category');
        local_attendancecatexport_extend_navigation_category_settings($node, $context);
        $this->assertFalse($node->get('local_attendancecatexport', \navigation_node::TYPE_SETTING));
        $this->expectException(\required_capability_exception::class);
        report::require_export_capability($context);
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
            ],
        ])->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(event\report_exported::class, $event);
        $this->assertEquals($context, $event->get_context());
        $this->assertEquals('csv', $event->other['dataformat']);
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
     * @return int The session id.
     */
    protected function add_session(int $attendanceid, int $sessdate, int $groupid = 0, bool $taken = true): int {
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
            'statusset' => 0,
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
