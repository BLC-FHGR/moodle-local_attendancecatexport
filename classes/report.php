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

use cm_info;
use context_course;
use context_coursecat;
use context_module;
use core_collator;
use core_course_category;
use stdClass;

/**
 * Builds the attendance export for all courses of a course category.
 *
 * The point calculation replicates mod_attendance_summary exactly (same SQL for taken sessions and
 * for the maximum points per group), but runs a fixed number of queries per attendance instance and
 * a single group membership query per course instead of one query per user.
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** @var string Percentage basis: all sessions (including sessions not yet taken). */
    public const BASIS_ALL = 'all';

    /** @var string Percentage basis: taken sessions only. */
    public const BASIS_TAKEN = 'taken';

    /** @var string Capability required in the category context. */
    public const CAPABILITY = 'mod/attendance:viewsummaryreports';

    /** @var core_course_category The category to export. */
    protected core_course_category $category;

    /** @var bool Whether courses of subcategories are included. */
    protected bool $includesubcategories;

    /** @var float Threshold in percent. */
    protected float $threshold;

    /** @var string One of the BASIS_* constants. */
    protected string $basis;

    /** @var array Filters, see {@see self::DEFAULT_FILTERS}. */
    protected array $filters;

    /** @var int Current time; sessions starting up to this time have taken place. */
    protected int $now;

    /** @var string[] Cache of nested category names, indexed by category id. */
    protected array $categorynames = [];

    /**
     * Default filters.
     *
     * Course filters (date bounds are inclusive timestamps, 0 disables the bound):
     * - startfrom / startto: range of course.startdate.
     * - endfrom / endto: range of course.enddate. Courses without end date (0) always match.
     * - includehidden: whether hidden courses (course.visible = 0) are included.
     *
     * Row filters:
     * - belowthresholdonly: only rows below the threshold, see {@see self::is_below_threshold()}.
     *
     * @var array
     */
    public const DEFAULT_FILTERS = [
        'startfrom' => 0,
        'startto' => 0,
        'endfrom' => 0,
        'endto' => 0,
        'includehidden' => false,
        'belowthresholdonly' => false,
    ];

    /**
     * Constructor.
     *
     * @param core_course_category $category The category to export.
     * @param bool $includesubcategories Whether courses of subcategories are included.
     * @param float $threshold Threshold in percent (0-100).
     * @param string $basis One of the BASIS_* constants.
     * @param array $filters Filters, see {@see self::DEFAULT_FILTERS}.
     */
    public function __construct(
        core_course_category $category,
        bool $includesubcategories = true,
        float $threshold = 80,
        string $basis = self::BASIS_ALL,
        array $filters = []
    ) {
        if (!in_array($basis, [self::BASIS_ALL, self::BASIS_TAKEN])) {
            throw new \coding_exception('Invalid basis: ' . $basis);
        }
        $unknown = array_diff_key($filters, self::DEFAULT_FILTERS);
        if ($unknown) {
            throw new \coding_exception('Unknown filters: ' . implode(', ', array_keys($unknown)));
        }
        $this->category = $category;
        $this->includesubcategories = $includesubcategories;
        $this->threshold = $threshold;
        $this->basis = $basis;
        $this->filters = $filters + self::DEFAULT_FILTERS;
        $this->now = time();
    }

    /**
     * Whether the current user may export the given category.
     *
     * @param context_coursecat $context The category context.
     * @return bool
     */
    public static function can_export(context_coursecat $context): bool {
        return has_capability(self::CAPABILITY, $context);
    }

    /**
     * Throws an exception if the current user may not export the given category.
     *
     * @param context_coursecat $context The category context.
     */
    public static function require_export_capability(context_coursecat $context): void {
        require_capability(self::CAPABILITY, $context);
    }

    /**
     * Returns the courses to export, with the course filters applied.
     *
     * Filtering uses the course fields cached by core_course_category, so no attendance data is loaded
     * for courses that are filtered out. Courses in which the capability is not granted (e.g. prohibited
     * by an override in a subcategory or in the course) are excluded as well.
     *
     * @return \core_course_list_element[]
     */
    public function get_courses(): array {
        $courses = $this->category->get_courses(['recursive' => $this->includesubcategories]);
        return array_filter($courses, function (\core_course_list_element $course): bool {
            return $this->course_matches_filters($course)
                && has_capability(self::CAPABILITY, context_course::instance($course->id));
        });
    }

    /**
     * Whether a course matches the course filters.
     *
     * @param \core_course_list_element $course The course.
     * @return bool
     */
    protected function course_matches_filters(\core_course_list_element $course): bool {
        $filters = $this->filters;
        if (!$filters['includehidden'] && !$course->visible) {
            return false;
        }
        if ($filters['startfrom'] && $course->startdate < $filters['startfrom']) {
            return false;
        }
        if ($filters['startto'] && $course->startdate > $filters['startto']) {
            return false;
        }
        // Courses without end date are considered ongoing and always match the end date filters.
        if ($course->enddate) {
            if ($filters['endfrom'] && $course->enddate < $filters['endfrom']) {
                return false;
            }
            if ($filters['endto'] && $course->enddate > $filters['endto']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns the column headers for the export, indexed by column key.
     *
     * @return string[]
     */
    public function get_columns(): array {
        $keys = [
            'category', 'courseshortname', 'coursefullname', 'lastname', 'firstname', 'email', 'idnumber',
            'numtakensessions', 'numallsessions', 'points', 'maxpoints', 'percenttaken', 'percentall', 'thresholdmet',
        ];
        $columns = [];
        foreach ($keys as $key) {
            $columns[$key] = get_string('col_' . $key, 'local_attendancecatexport');
        }
        return $columns;
    }

    /**
     * Returns the export rows, one per course and listed participant.
     *
     * Courses without attendance instance are skipped. If the filter belowthresholdonly is set, only
     * rows below the threshold are returned.
     *
     * @return \Generator Rows indexed by the keys of {@see get_columns()}.
     */
    public function get_rows(): \Generator {
        $yes = get_string('yes');
        $no = get_string('no');
        foreach ($this->get_courses() as $course) {
            $summaries = $this->get_course_summaries($course);
            if (empty($summaries)) {
                continue;
            }
            $formatoptions = ['context' => context_course::instance($course->id), 'escape' => false];
            $categoryname = $this->get_category_name($course->category);
            $shortname = format_string($course->shortname, true, $formatoptions);
            $fullname = format_string($course->fullname, true, $formatoptions);

            foreach ($summaries as $summary) {
                if ($this->filters['belowthresholdonly'] && !$this->is_below_threshold($summary)) {
                    continue;
                }
                yield [
                    'category' => $categoryname,
                    'courseshortname' => $shortname,
                    'coursefullname' => $fullname,
                    'lastname' => $summary->lastname,
                    'firstname' => $summary->firstname,
                    'email' => $summary->email,
                    'idnumber' => $summary->idnumber,
                    'numtakensessions' => $summary->numtakensessions,
                    'numallsessions' => $summary->numallsessions,
                    'points' => round($summary->points, 2),
                    'maxpoints' => round($this->get_basis_maxpoints($summary), 2),
                    'percenttaken' => round($summary->takenpercentage * 100, 2),
                    'percentall' => round($summary->allpercentage * 100, 2),
                    'thresholdmet' => $this->meets_threshold($summary) ? $yes : $no,
                ];
            }
        }
    }

    /**
     * Returns the maximum points of the selected basis.
     *
     * @param stdClass $summary A summary returned by {@see get_course_summaries()}.
     * @return float
     */
    protected function get_basis_maxpoints(stdClass $summary): float {
        return $this->basis === self::BASIS_TAKEN ? $summary->takenmaxpoints : $summary->allmaxpoints;
    }

    /**
     * Whether the percentage of the selected basis reaches the threshold.
     *
     * Compares the unrounded values (points * 100 >= threshold * maxpoints), so a percentage that is only
     * rounded up to the threshold in the export does not meet it. Without maximum points the percentage is
     * 0, as in attendance_calc_fraction().
     *
     * @param stdClass $summary A summary returned by {@see get_course_summaries()}.
     * @return bool
     */
    public function meets_threshold(stdClass $summary): bool {
        $maxpoints = $this->get_basis_maxpoints($summary);
        if ($maxpoints <= 0) {
            return $this->threshold <= 0;
        }
        // The tolerance only absorbs floating point noise of the multiplication.
        return $summary->points * 100 - $this->threshold * $maxpoints >= -1e-9;
    }

    /**
     * Whether a row counts as below the threshold for the belowthresholdonly filter.
     *
     * Rows whose percentage is undefined (no maximum points of the selected basis, e.g. no sessions or
     * only statuses without points) are not below the threshold. Participants without taken sessions are
     * not below the threshold either, except with the basis "all sessions" if sessions relevant to them
     * have already taken place (started).
     *
     * @param stdClass $summary A summary returned by {@see get_course_summaries()}.
     * @return bool
     */
    public function is_below_threshold(stdClass $summary): bool {
        if ($this->get_basis_maxpoints($summary) <= 0) {
            return false;
        }
        // With the basis "taken sessions", participants without taken sessions have no maximum points.
        if ($summary->numtakensessions == 0 && $summary->numpastsessions == 0) {
            return false;
        }
        return !$this->meets_threshold($summary);
    }

    /**
     * Computes the aggregated attendance of every listed participant of a course.
     *
     * Points and maximum points are summed over all attendance instances of the course in which the
     * participant is listed; percentages are computed from these sums (not averaged).
     *
     * Each returned object contains: userid, firstname, lastname, email, idnumber, numtakensessions,
     * numallsessions, numpastsessions (sessions that have already started), points, takenmaxpoints,
     * allmaxpoints, maxpossiblepoints, takenpercentage, allpercentage, maxpossiblepercentage
     * (percentages as fractions between 0 and 1).
     *
     * The maximum possible points are the points the participant reaches with full points in all sessions
     * not taken for them yet, as in mod_attendance_summary::get_all_sessions_summary_for(): points plus
     * the difference between the maximum points of all sessions and of the taken sessions.
     *
     * @param int|\core_course_list_element $course The course id, or the course from {@see get_courses()},
     *                                             which saves a query per course.
     * @return stdClass[] Summaries indexed by user id, sorted by last name and first name. Empty if the
     *                    course has no attendance instance.
     */
    public function get_course_summaries(int|\core_course_list_element $course): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/attendance/locallib.php');

        if ($course instanceof \core_course_list_element) {
            // With cacherev, get_fast_modinfo() takes the course from its cache instead of the database.
            $courseid = (int) $course->id;
            $modinfo = get_fast_modinfo((object) ['id' => $courseid, 'cacherev' => $course->cacherev]);
            $startdate = (int) $course->startdate;
        } else {
            $courseid = $course;
            $modinfo = get_fast_modinfo($courseid);
            // Not $modinfo->get_course(): a cached modinfo may hold a partial course record without startdate.
            $startdate = (int) get_course($courseid)->startdate;
        }

        $summaries = [];
        $usergroups = null;
        foreach ($modinfo->get_instances_of('attendance') as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            $users = $this->get_listed_users($cm);
            if (empty($users)) {
                continue;
            }

            // Same condition as mod_attendance_summary::with_groups().
            $withgroups = $cm->effectivegroupmode > 0;
            $userspoints = $this->get_users_points($cm->instance, $startdate, $withgroups);
            $maxpointsbygroup = $this->get_maxpoints_by_group($cm->instance, $startdate, $withgroups);
            if ($withgroups && $usergroups === null) {
                $usergroups = $this->get_user_groups($courseid);
            }

            foreach ($users as $userid => $user) {
                if (!isset($summaries[$userid])) {
                    $summaries[$userid] = (object) [
                        'userid' => (int) $userid,
                        'firstname' => $user->firstname,
                        'lastname' => $user->lastname,
                        'email' => $user->email,
                        'idnumber' => $user->idnumber,
                        'numtakensessions' => 0,
                        'numallsessions' => 0,
                        'numpastsessions' => 0,
                        'points' => 0.0,
                        'takenmaxpoints' => 0.0,
                        'allmaxpoints' => 0.0,
                    ];
                }
                $summary = $summaries[$userid];

                // See mod_attendance_summary::get_taken_sessions_summary_for().
                if (isset($userspoints[$userid])) {
                    $summary->numtakensessions += (int) $userspoints[$userid]->numtakensessions;
                    $summary->points += (float) $userspoints[$userid]->points;
                    $summary->takenmaxpoints += (float) $userspoints[$userid]->maxpoints;
                }

                // See mod_attendance_summary::get_all_sessions_summary_for().
                $groupids = [0];
                if ($withgroups && !empty($usergroups[$userid])) {
                    $groupids = array_merge($groupids, $usergroups[$userid]);
                }
                foreach ($groupids as $groupid) {
                    if (isset($maxpointsbygroup[$groupid])) {
                        $summary->numallsessions += (int) $maxpointsbygroup[$groupid]->numsessions;
                        $summary->numpastsessions += (int) $maxpointsbygroup[$groupid]->numpastsessions;
                        $summary->allmaxpoints += (float) $maxpointsbygroup[$groupid]->maxpoints;
                    }
                }
            }
        }

        foreach ($summaries as $summary) {
            $summary->maxpossiblepoints = $summary->points + ($summary->allmaxpoints - $summary->takenmaxpoints);
            $summary->takenpercentage = attendance_calc_fraction($summary->points, $summary->takenmaxpoints);
            $summary->allpercentage = attendance_calc_fraction($summary->points, $summary->allmaxpoints);
            $summary->maxpossiblepercentage = attendance_calc_fraction($summary->maxpossiblepoints, $summary->allmaxpoints);
            $summary->sortkey = $summary->lastname . "\t" . $summary->firstname . "\t" . sprintf('%010d', $summary->userid);
        }
        core_collator::asort_objects_by_property($summaries, 'sortkey');
        foreach ($summaries as $summary) {
            unset($summary->sortkey);
        }

        return $summaries;
    }

    /**
     * Returns the participants listed in an attendance instance.
     *
     * Mirrors mod_attendance_structure::get_users(): users with mod/attendance:canbelisted in the module
     * context, restricted to the groups of the activity's grouping if one is set. Additionally, only
     * active enrolments (active, started and not ended) of user accounts that are not suspended are
     * considered, and temporary users are ignored.
     *
     * @param cm_info $cm The attendance course module.
     * @return stdClass[] Users (id, firstname, lastname, email, idnumber) indexed by id.
     */
    protected function get_listed_users(cm_info $cm): array {
        global $DB;

        $groupids = 0;
        if (!empty($cm->groupingid)) {
            $groupids = array_keys(groups_get_all_groups($cm->course, 0, $cm->groupingid, 'g.id'));
        }
        [$enrolledsql, $params] = get_enrolled_sql(
            context_module::instance($cm->id),
            'mod/attendance:canbelisted',
            $groupids,
            true
        );
        $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.idnumber
                  FROM {user} u
                  JOIN ($enrolledsql) je ON je.id = u.id
                 WHERE u.suspended = 0";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Computes points of taken sessions per user for one attendance instance.
     *
     * Same query as mod_attendance_summary::compute_users_points() without user or date filters.
     *
     * @param int $attendanceid The attendance instance id.
     * @param int $coursestartdate The course start date; earlier sessions are ignored.
     * @param bool $withgroups Whether the instance works in group mode.
     * @return stdClass[] Records (userid, numtakensessions, points, maxpoints) indexed by user id.
     */
    protected function get_users_points(int $attendanceid, int $coursestartdate, bool $withgroups): array {
        global $DB;

        $params = [
            'attid' => $attendanceid,
            'attid2' => $attendanceid,
            'cstartdate' => $coursestartdate,
        ];

        if ($withgroups) {
            $joingroup = 'LEFT JOIN {groups_members} gm ON (gm.userid = atl.studentid AND gm.groupid = ats.groupid)';
            $where = ' AND (ats.groupid = 0 or gm.id is NOT NULL)';
        } else {
            $joingroup = '';
            $where = ' AND ats.groupid = 0';
        }

        $sql = "SELECT atl.studentid AS userid, COUNT(DISTINCT ats.id) AS numtakensessions,
                       SUM(stg.grade) AS points, SUM(stm.maxgrade) AS maxpoints
                  FROM {attendance_sessions} ats
                  JOIN {attendance_log} atl ON (atl.sessionid = ats.id)
                  JOIN {attendance_statuses} stg ON (stg.id = atl.statusid AND stg.deleted = 0 AND stg.visible = 1)
                  JOIN (SELECT setnumber, MAX(grade) AS maxgrade
                          FROM {attendance_statuses}
                         WHERE attendanceid = :attid2
                           AND deleted = 0
                           AND visible = 1
                      GROUP BY setnumber) stm
                    ON (stm.setnumber = ats.statusset)
                  {$joingroup}
                 WHERE ats.attendanceid = :attid
                   AND ats.sessdate >= :cstartdate
                   AND ats.lasttaken != 0
                   {$where}
              GROUP BY atl.studentid";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Computes the number of sessions and their maximum points per group for one attendance instance.
     *
     * Same query as mod_attendance_summary::compute_maxpoints_by_group_session(), additionally counting
     * the sessions that have already started. Group id 0 holds the sessions for all participants and is
     * always present.
     *
     * @param int $attendanceid The attendance instance id.
     * @param int $coursestartdate The course start date; earlier sessions are ignored.
     * @param bool $withgroups Whether the instance works in group mode.
     * @return stdClass[] Records (groupid, numsessions, numpastsessions, maxpoints) indexed by group id.
     */
    protected function get_maxpoints_by_group(int $attendanceid, int $coursestartdate, bool $withgroups): array {
        global $DB;

        $params = [
            'attid' => $attendanceid,
            'attid2' => $attendanceid,
            'cstartdate' => $coursestartdate,
            'now' => $this->now,
        ];

        $where = '';
        if (!$withgroups) {
            $where = 'AND sess.groupid = 0';
        }

        $sql = "SELECT sess.groupid, COUNT(*) AS numsessions, SUM(stamax.maxgrade) AS maxpoints,
                       SUM(CASE WHEN sess.sessdate <= :now THEN 1 ELSE 0 END) AS numpastsessions
                  FROM {attendance_sessions} sess
                  JOIN (SELECT setnumber, MAX(grade) AS maxgrade
                          FROM {attendance_statuses}
                         WHERE attendanceid = :attid2
                           AND deleted = 0
                           AND visible = 1
                      GROUP BY setnumber) stamax
                    ON (stamax.setnumber = sess.statusset)
                 WHERE sess.attendanceid = :attid
                   AND sess.sessdate >= :cstartdate
                   {$where}
              GROUP BY sess.groupid";
        $maxpoints = $DB->get_records_sql($sql, $params);

        if (!isset($maxpoints[0])) {
            $maxpoints[0] = (object) ['groupid' => 0, 'numsessions' => 0, 'numpastsessions' => 0, 'maxpoints' => 0];
        }
        return $maxpoints;
    }

    /**
     * Returns the group ids of all group members of a course, in one query.
     *
     * Replaces the per-user call groups_get_all_groups($courseid, $userid) in
     * mod_attendance_summary::get_all_sessions_summary_for() and applies the same visibility rule: without
     * moodle/course:viewhiddengroups, groups with visibility "none" are ignored.
     *
     * @param int $courseid The course id.
     * @return int[][] Group ids indexed by user id.
     */
    protected function get_user_groups(int $courseid): array {
        global $DB;

        $params = ['courseid' => $courseid];
        $where = '';
        if (!has_capability('moodle/course:viewhiddengroups', context_course::instance($courseid))) {
            $where = 'AND g.visibility <> :visibilitynone';
            $params['visibilitynone'] = GROUPS_VISIBILITY_NONE;
        }

        $sql = "SELECT gm.id, gm.userid, gm.groupid
                  FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = :courseid
                       $where";
        $usergroups = [];
        $records = $DB->get_recordset_sql($sql, $params);
        foreach ($records as $record) {
            $usergroups[$record->userid][] = (int) $record->groupid;
        }
        $records->close();
        return $usergroups;
    }

    /**
     * Returns the nested name of a category (e.g. "Parent / Child").
     *
     * @param int $categoryid The category id.
     * @return string
     */
    protected function get_category_name(int $categoryid): string {
        if (!isset($this->categorynames[$categoryid])) {
            $category = core_course_category::get($categoryid, MUST_EXIST, true);
            $this->categorynames[$categoryid] = $category->get_nested_name(false, ' / ', ['escape' => false]);
        }
        return $this->categorynames[$categoryid];
    }
}
