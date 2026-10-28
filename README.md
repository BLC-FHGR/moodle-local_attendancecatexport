# Attendance category export (local_attendancecatexport)

Exports the attendance of all courses in a Moodle course category (optionally including its
subcategories) into a single spreadsheet, with one row per course and participant.

It is intended for programmes with a mandatory minimum attendance (for example 80 %), where each
course corresponds to one module. Managers and programme leads can check the attendance of all
modules at once instead of opening the attendance report of every single course.

## Requirements

- Moodle 5.2
- [mod_attendance](https://moodle.org/plugins/mod_attendance) (version 2026042102 or later)

## Installation

1. Copy the plugin to `local/attendancecatexport` in your Moodle code (on Moodle 5.x with the
   `public/` directory layout: `public/local/attendancecatexport`).
2. Visit *Site administration > Notifications* to complete the installation.

Alternatively, install the ZIP file via *Site administration > Plugins > Install plugins*.

## Permission

The plugin defines no capability of its own. It uses the existing capability
`mod/attendance:viewsummaryreports` of mod_attendance, checked in the **course category context**.
By default this capability is granted to the *Manager* role. To give programme leads access, assign
them a role with this capability in the respective course category.

The capability is additionally checked in the context of every exported course. Courses in which it
is prevented or prohibited (by an override in a subcategory or in the course itself) are not exported.

## Usage

1. Open the course category and choose **Attendance export** in the category's *More* menu
   (`/local/attendancecatexport/export.php?categoryid=<id>`).
2. Choose the options:
   - **Include subcategories** (default: yes)
   - **Threshold (%)** (default: 80)
   - **Percentage basis**: *All sessions* (default) or *Taken sessions*
   - **Only export participants below the threshold** (default: no, see below)
   - **Format**: Excel (xlsx), CSV or ODS (only enabled data formats are listed)
3. Optionally restrict the courses in the **Course filters** section (see below).
4. Click **Download**.

Each download is logged with the event `\local_attendancecatexport\event\report_exported`, including
all selected options and filters.

If no rows remain for the selected options, no file is downloaded. Instead, a notice is shown on the
page (and no event is logged).

Large categories: the rows are computed course by course and streamed into the file. The PHP time
limit is lifted and the memory limit raised (`MEMORY_EXTRA`) before the computation starts.

### Only participants below the threshold

If **Only export participants below the threshold** is checked, only rows whose percentage of the
selected basis is below the threshold are exported.

- The filter applies per row, i.e. per course and participant. A participant below the threshold in
  one course and above it in another only appears with the first course.
- The comparison uses the unrounded values (see *Threshold met* below).
- **Participants without taken sessions** (no attendance recorded yet) are not considered below the
  threshold, with one exception: with the basis *All sessions*, they are below the threshold if
  sessions relevant to them (sessions for all participants or for one of their groups) have already
  started. With the basis *Taken sessions* their percentage is undefined, and with only future sessions
  there is nothing they could have missed yet.
- **Rows without maximum points** for the selected basis (no sessions yet, or only sessions whose
  status set has no status with points) have an undefined percentage and are not considered below
  the threshold either.
- These rules only affect the filter. The *Threshold met* column shows *No* for such rows, as their
  percentage is reported as 0 (as in the activity report).

### Course filters

| Filter | Description |
| --- | --- |
| Course start date from / to | Only courses whose start date lies within the range. |
| Course end date from / to | Only courses whose end date lies within the range. Courses without an end date are considered ongoing and are always included. |
| Include hidden courses | Hidden courses are excluded by default. If checked, hidden courses are included (as far as the user may view them). |

- Each date filter is optional and has to be enabled with its *Enable* checkbox.
- Both bounds are inclusive: "to" covers the whole selected day in the user's timezone.
- The "from" date must not be after the "to" date.
- The filters are applied to the course list before any attendance data is loaded, so filtered-out
  courses do not cause any additional database load.

## Columns

| Column | Description |
| --- | --- |
| Course category | Category of the course, including its parent categories (e.g. `Programme / Year 1`). |
| Course short name | Short name of the course. |
| Course full name | Full name of the course. |
| Last name | Last name of the participant. |
| First name | First name of the participant. |
| Email address | Email address of the participant. |
| ID number | ID number of the participant's user account. |
| Taken sessions | Number of taken sessions in which the participant has a status. |
| Total sessions | Number of all sessions relevant for the participant (common sessions plus sessions of the participant's groups), including sessions not yet taken. |
| Points | Points achieved in the taken sessions. |
| Max. points | Maximum points, depending on the selected basis: of all sessions (*All sessions*) or of the taken sessions only (*Taken sessions*). |
| % taken sessions | Points / maximum points of the taken sessions, in percent (2 decimals). |
| % all sessions | Points / maximum points of all sessions, in percent (2 decimals). |
| Threshold met | *Yes* if the percentage of the selected basis is at least the threshold, otherwise *No*. The unrounded values are compared, so e.g. 66.666 % (exported as 66.67) does not meet a threshold of 66.67. |

## Calculation

The values match the attendance report of the activity (*Report > Summary*), because the plugin
uses the same calculation as `mod_attendance_summary`:

- The maximum points of a session are the highest grade of the session's status set. Only visible
  and non-deleted statuses are considered.
- Only sessions on or after the course start date are counted.
- In group mode, a participant is counted for common sessions and for sessions of their own groups.
  Without group mode, group sessions are ignored.

The calculation is reimplemented with batched queries rather than calling `mod_attendance_summary`
per participant, which would run one group query per participant. The number of queries does not
depend on the number of participants: four per attendance activity (participants, capability
overrides, points, maximum points), one group membership query per course in group mode, plus the
course module cache. The PHPUnit tests compare the results with `mod_attendance_summary`.

For reference, a category with 120 courses, 160 attendance activities, 3,600 participant rows and
about 56,000 attendance log entries was exported with about 800 queries in about 0.5 seconds and
23 MB peak memory (warm caches, local database).

Further rules:

- **Several attendance activities in one course** are aggregated: points and maximum points are
  summed over all activities, and the percentages are computed from these sums (percentages are
  not averaged).
- **Participants** are the users listed by the attendance activity: users with the capability
  `mod/attendance:canbelisted` in the activity context, restricted to the groups of the activity's
  grouping if one is set. Unlike the activity report, only **active participants** are exported:
  the enrolment must be active, already started and not ended, and the user account must not be
  suspended. Temporary users of mod_attendance are not exported.
- A participant only counts in the attendance activities in which they are listed; activities in which
  they are not listed (e.g. because of a capability override) are ignored for them.
- **Courses without an attendance activity** are skipped. Hidden attendance activities are included.
- Courses in hidden subcategories are treated like all other courses (access is controlled by the
  capability, not by the category visibility). Note that Moodle creates courses in a hidden category
  as hidden by default, so they are only exported with *Include hidden courses*.

## Known limitations

- The spreadsheet formats are written by Moodle core (`core\dataformat`). In Moodle 5.2, all values
  including the numbers end up as text cells in Excel and ODS files, because core passes every value
  through its formula escaping, which returns strings. Use CSV, or convert the columns in the
  spreadsheet application, if you need to calculate with the numbers.
- Values starting with `=`, `+`, `-` or `@` (e.g. in names) are prefixed with an apostrophe by
  Moodle core to prevent formula injection.

## Privacy

The plugin does not store any personal data (null privacy provider). It only exports data stored by
mod_attendance.

## Development

Run the PHPUnit tests from the Moodle root:

```bash
vendor/bin/phpunit --testsuite local_attendancecatexport_testsuite
```

Check the coding style with [moodle-plugin-ci](https://moodlehq.github.io/moodle-plugin-ci/):

```bash
moodle-plugin-ci phpcs --max-warnings 0 local/attendancecatexport
```

## License

GNU GPL v3 or later. See <http://www.gnu.org/copyleft/gpl.html>.
