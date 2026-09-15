# Changelog

## 1.4.0 (2026-09-15)

Adds the WordPress booking and learning plugin to the repository so the whole
teaching stack is backed up in one place, and repairs Google Meet recording
delivery end to end.

### WordPress booking plugin 14.16.0 (`wordpress/german-teacher-booking/`)

Google Meet recordings were present in Google Drive but never reached the
student panel. Three separate faults were responsible:

- The recording cron only examined bookings whose `meet_join_link` column was
  filled. Classes whose Meet link lived in the shared `roomeet_join_link`
  column, or that predated the column, were never checked. The query now also
  matches the calendar event id, the `meet` provider flag and a
  `meet.google.com` join link.
- Matching a Drive file to a class was a plain substring search over a date
  window that fell back to the oldest file in range, which could attach another
  student's video. Matching is now scored across several signals: the recording
  Google itself attaches to that calendar event, the Meet meeting code in the
  file name, the event title and student name, how close the file creation time
  is to the class, and whether the video length fits the class length. A
  recording is stored automatically only when the best candidate is clearly
  ahead of the runner-up; otherwise the reason is logged and a human decides.
- A link saved on the booking stayed invisible in the panel when no session row
  carried that booking id. The sync now also matches the session by student
  email and class date, and repairs the missing link.

The video search screen gained a Google Meet box that lists candidate
recordings per past class with a score and the reasons behind it, assigns a
chosen file to that exact session, and can scan every past Meet class in the
background to recover older videos.

Earlier in the same plugin line: the student dashboard tabs were repaired, card
order and colours became configurable, a connection health screen was added,
and the complete export/import and two-site sync were introduced.

### Control plane

- The WordPress host is resolved to its IPv4 address and explicitly allowed in
  `ufw` on ports 80 and 443, with a fail2ban `ignoreip` drop-in written before
  fail2ban starts and any existing ban lifted. This fixes the `cURL error 28`
  timeout the WordPress site hit when calling the BigBlueButton API.
- `bcpctl health` reports the whitelist and ban state; `bcpctl repair` and the
  new `bcpctl whitelist-wordpress` restore access after an IP change.

## 1.3.11 and earlier

See the commit history for the provisioning, Greenlight, SFU, Telegram and
HTML5 authentication fixes that led to this release.
