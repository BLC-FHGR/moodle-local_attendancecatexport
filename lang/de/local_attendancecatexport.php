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

/**
 * German language strings for local_attendancecatexport.
 *
 * @package    local_attendancecatexport
 * @copyright  2026 Ramon Heeb
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['basis'] = 'Basis für Prozentwert';
$string['basis_all'] = 'Alle Termine';
$string['basis_help'] = 'Welcher Prozentwert für die Spalten "Schwelle erfüllt" und "max. Punkte" verwendet wird. "Alle Termine" bezieht die Punkte auf alle Termine der Aktivität ab Kursbeginn, auch auf noch nicht erfasste (wie die Zusammenfassung "Alle Termine" im Bericht der Präsenzkontrolle). "Erfasste Termine" bezieht die Punkte nur auf bereits erfasste Termine.';
$string['basis_taken'] = 'Erfasste Termine';
$string['belowthresholdonly'] = 'Nur Teilnehmende unter der Schwelle exportieren';
$string['belowthresholdonly_help'] = 'Wenn aktiviert, werden nur Zeilen (Kurs und Teilnehmer/in) exportiert, deren Prozentwert der gewählten Basis unter der Schwelle liegt. Jeder Kurs wird einzeln beurteilt.

Teilnehmende ohne erfasste Termine gelten nicht als unter der Schwelle, ausser bei der Basis "Alle Termine", wenn für sie bereits Termine stattgefunden haben.';
$string['col_category'] = 'Kursbereich';
$string['col_coursefullname'] = 'Kurs-Vollname';
$string['col_courseshortname'] = 'Kurs-Kurzname';
$string['col_email'] = 'E-Mail';
$string['col_firstname'] = 'Vorname';
$string['col_idnumber'] = 'ID-Nummer';
$string['col_lastname'] = 'Nachname';
$string['col_maxpoints'] = 'max. Punkte';
$string['col_numallsessions'] = 'Anzahl Termine total';
$string['col_numtakensessions'] = 'Anzahl erfasste Termine';
$string['col_percentall'] = '% alle Termine';
$string['col_percenttaken'] = '% erfasste Termine';
$string['col_points'] = 'Punkte';
$string['col_thresholdmet'] = 'Schwelle erfüllt';
$string['coursefilters'] = 'Kursfilter';
$string['dataformat'] = 'Format';
$string['endfrom'] = 'Kursende von';
$string['endfrom_help'] = 'Die Filter zum Kursende beziehen nur Kurse ein, deren Enddatum im gewählten Zeitraum liegt. Kurse ohne Enddatum gelten als laufend und werden immer einbezogen.';
$string['endto'] = 'Kursende bis';
$string['errordaterange'] = 'Das Datum "bis" darf nicht vor dem Datum "von" liegen.';
$string['eventreportexported'] = 'Präsenz-Export Kursbereich heruntergeladen';
$string['exportfilename'] = 'praesenz_{$a->category}_{$a->date}';
$string['exportintro'] = 'Exportiert die Anwesenheit aller Teilnehmenden für jeden Kurs mit Präsenzkontrolle in diesem Kursbereich. Enthält ein Kurs mehrere Präsenzkontrollen, werden deren Punkte zusammengezählt.';
$string['exportmenu'] = 'Präsenz-Export';
$string['includehidden'] = 'Verborgene Kurse einbeziehen';
$string['includesubcategories'] = 'Unterbereiche einbeziehen';
$string['norows'] = 'Für die gewählten Optionen wurden keine Daten gefunden. Es wurde keine Datei erstellt.';
$string['pluginname'] = 'Präsenz-Export Kursbereich';
$string['privacy:metadata'] = 'Das Plugin Präsenz-Export Kursbereich speichert keine personenbezogenen Daten. Es exportiert nur Daten, die von der Präsenzkontrolle gespeichert werden.';
$string['startfrom'] = 'Kursbeginn von';
$string['startto'] = 'Kursbeginn bis';
$string['threshold'] = 'Schwelle (%)';
$string['threshold_help'] = 'Mindestanwesenheit in Prozent. Die Spalte "Schwelle erfüllt" zeigt "Ja", wenn der Prozentwert der gewählten Basis mindestens diesem Wert entspricht.';
$string['thresholdinvalid'] = 'Bitte einen Wert zwischen 0 und 100 eingeben.';
