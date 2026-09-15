# Operations

## Routine commands

```bash
sudo bcpctl health
sudo bcpctl provision-status
sudo bcpctl queue
sudo bbb-record --list-recent
sudo bbb-record --watch
sudo journalctl -u bcp-worker -n 200 --no-pager
sudo journalctl -u telegram-bot-api -n 200 --no-pager
sudo journalctl -u bcp-provision -n 200 --no-pager
```

## WordPress connectivity (cURL error 28 / HTTP status 0)

Provisioning resolves the `WORDPRESS_URL` host to its IPv4 address, adds explicit
`ufw` allow rules for it on ports 80 and 443, writes
`/etc/fail2ban/jail.d/bcp-wordpress.local` so fail2ban never bans that address,
and lifts any existing ban. If the WordPress site reports a connection timeout
to the BigBlueButton API:

1. Run `sudo bcpctl health` – the report shows whether the WordPress IP is
   whitelisted in `ufw` and whether fail2ban has banned it.
2. Run `sudo bcpctl whitelist-wordpress` (also part of `sudo bcpctl repair`) to
   restore the whitelist and unban the address. Re-run it after the WordPress
   host changes its IP address.
3. If the timeout persists although the local firewall accepts the address, the
   block is outside this server: ask the datacenter of the BigBlueButton server
   to allow inbound TCP 80/443 from the WordPress IP in the upstream or edge
   firewall, and ask the WordPress host to confirm outbound TCP 443 with
   `curl -4 -Iv --connect-timeout 15 https://<BBB_HOSTNAME>/bigbluebutton/api`.

## Update procedure

1. Confirm no meeting is active.
2. Confirm the recording queue is empty.
3. Create a provider snapshot.
4. Back up `/etc/bigbluebutton`, `/etc/bbb-control-plane.env`, and the application database.
5. Update the source checkout.
6. Run preflight.
7. Run provisioning again.
8. Execute health checks and a test recording.
9. Remove the snapshot only after validation.

## Disk pressure

The retention worker warns below `MIN_FREE_GB`. It deletes only locally published composite artifacts associated with completed queue receipts. Presentation and raw retention should be enforced with a separately reviewed policy because those assets are required for rebuild operations.

## Failed uploads

Jobs retry with bounded backoff and move to `/var/lib/bcp/failed` after 20 attempts. Do not move a failed job to `done`. Correct the reported condition, reset `attempts` only after inspection, and move the JSON file back to `/var/lib/bcp/jobs`.

## Telegram object ceiling

The configured ceiling defaults to 1900 MiB. Output above the ceiling is rejected before upload. Adjust encoding or implement reviewed segmentation. Never silently truncate a recording.
