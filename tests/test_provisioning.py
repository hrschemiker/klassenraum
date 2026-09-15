import unittest
from pathlib import Path


ROOT = Path(__file__).parents[1]


class ProvisioningSafetyTests(unittest.TestCase):
    def test_wordpress_bridge_does_not_intercept_other_telegram_bots(self):
        bridge = (ROOT / "wordpress" / "gtbp-recording-bridge" / "gtbp-recording-bridge.php").read_text(encoding="utf-8")
        self.assertNotIn("pre_http_request", bridge)
        self.assertNotIn("telegram_transport", bridge)
        self.assertIn("bbb_presentation_link", bridge)
        self.assertIn("bbb_video_link", bridge)

    def test_controller_exports_complete_wordpress_configuration(self):
        controller = (ROOT / "controller.py").read_text(encoding="utf-8")
        for requirement in ("COPY WORDPRESS CONFIG", "bbb-conf --secret", "personal_bbb_url", "personal_bbb_secret", "recording_bridge_gateway", "recording_bridge_secret"):
            self.assertIn(requirement, controller)

    def test_wordpress_host_is_whitelisted_in_firewall_and_fail2ban(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn('ufw allow from "$wordpress_ip" to any port 443 proto tcp', script)
        self.assertIn("/etc/fail2ban/jail.d/bcp-wordpress.local", script)
        self.assertIn('fail2ban-client unban "$wordpress_ip"', script)
        # The whitelist and the fail2ban drop-in must exist before fail2ban starts.
        self.assertLess(script.index("whitelist_wordpress_host\nufw --force enable"), script.index("start_required_service fail2ban"))
        self.assertIn("unban_wordpress_host", script.split("start_required_service fail2ban", 1)[1])

    def test_repair_restores_wordpress_access(self):
        control = (ROOT / "provision" / "bcpctl").read_text(encoding="utf-8")
        self.assertIn("repair_wordpress_access", control)
        self.assertIn("wordpress_access_report", control)
        self.assertIn("fail2ban-client unban", control)
        self.assertIn("whitelist-wordpress", control)

    def test_recording_storage_is_never_removed(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertNotIn("rm -rf /var/bigbluebutton", script)
        self.assertIn("recordings under /var/bigbluebutton were preserved", script)

    def test_recovery_has_resume_repair_backup_and_final_retry(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        for requirement in ("bbb_core_healthy", "greenlight_healthy", "repair_packages", "backup_before_cleanup", "run_upstream_installer 3"):
            self.assertIn(requirement, script)

    def test_server_managed_launcher_has_heartbeat(self):
        launcher = (ROOT / "provision" / "launch.sh").read_text(encoding="utf-8")
        self.assertIn("systemd-run", launcher)
        self.assertIn("HEARTBEAT", launcher)

    def test_nginx_variables_survive_template_rendering(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        template = (ROOT / "provision" / "telegram-gateway.nginx.in").read_text(encoding="utf-8")
        self.assertIn("envsubst '${BRIDGE_SHARED_SECRET}'", script)
        self.assertIn("$http_x_bcp_gateway_secret", template)

    def test_greenlight_database_is_repaired_before_admin_creation(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn("bundle exec rails db:prepare", script)
        self.assertLess(script.index("greenlight_database_ready || prepare_greenlight_database"), script.index("create_or_promote_greenlight_admin\n"))

    def test_failed_services_emit_diagnostics(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn('journalctl --no-pager -u "$service_name" -n 160', script)

    def test_sfu_scheduler_repair_is_guarded_by_journal_signature(self):
        control = (ROOT / "provision" / "bcpctl").read_text(encoding="utf-8")
        self.assertIn("214/SETSCHEDULER", control)
        self.assertIn("CPUSchedulingPolicy=other", control)
        self.assertIn("systemctl is-active --quiet bbb-webrtc-sfu.service", control)

    def test_worker_is_disabled_until_telegram_migration(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn("systemctl disable --now bcp-worker", script)

    def test_provision_log_secrets_are_redacted(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn('text.replace(secret, "[REDACTED]")', script)
        self.assertIn('admin:create[$GREENLIGHT_ADMIN_NAME,$GREENLIGHT_ADMIN_EMAIL,$GREENLIGHT_ADMIN_PASSWORD]" >/dev/null 2>&1', script)

    def test_upstream_installer_uses_expected_umask(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn("umask 022; exec bash /tmp/bbb-install.sh", script)

    def test_sfu_config_permissions_are_repaired_for_service_user(self):
        control = (ROOT / "provision" / "bcpctl").read_text(encoding="utf-8")
        self.assertIn("systemctl show -p User --value bbb-webrtc-sfu.service", control)
        self.assertIn('chgrp -R "$sfu_group" /etc/bigbluebutton/bbb-webrtc-sfu', control)
        self.assertIn("sleep 8", control)

    def test_html5_config_permissions_and_auth_endpoint_are_repaired(self):
        control = (ROOT / "provision" / "bcpctl").read_text(encoding="utf-8")
        self.assertIn("repair_html5_config", control)
        self.assertIn("/etc/bigbluebutton/bbb-html5.yml", control)
        self.assertIn('chmod 0644 "$config_file"', control)
        self.assertIn("http://127.0.0.1:8901/userInfo", control)
        repair_case = control.split("repair)", 1)[1].split(";;", 1)[0]
        self.assertLess(repair_case.index("repair_html5_config"), repair_case.index("bbb-conf --restart"))

    def test_telegram_container_receives_required_environment(self):
        service = (ROOT / "provision" / "telegram-bot-api.service.in").read_text(encoding="utf-8")
        for variable in ("TELEGRAM_API_ID", "TELEGRAM_API_HASH", "TELEGRAM_WORK_DIR", "TELEGRAM_TEMP_DIR"):
            self.assertIn(f"-e {variable}=", service)

    def test_sfu_repair_precedes_telegram_repair(self):
        control = (ROOT / "provision" / "bcpctl").read_text(encoding="utf-8")
        repair_case = control.split("repair)", 1)[1].split(";;", 1)[0]
        self.assertLess(repair_case.index("repair_webrtc_sfu"), repair_case.index("repair_telegram_api"))

    def test_telegram_temp_uses_entrypoint_owned_work_directory(self):
        service = (ROOT / "provision" / "telegram-bot-api.service.in").read_text(encoding="utf-8")
        self.assertIn("TELEGRAM_TEMP_DIR=/var/lib/telegram-bot-api ", service)
        self.assertNotIn("TELEGRAM_TEMP_DIR=/var/lib/telegram-bot-api/temp", service)

    def test_greenlight_endpoint_uses_media_hostname_and_is_verified(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn('expected_endpoint="https://$BBB_HOSTNAME/bigbluebutton/"', script)
        self.assertIn("docker compose up -d --force-recreate", script)
        self.assertIn("Greenlight endpoint verified for the media hostname", script)
        self.assertIn('https://$BBB_HOSTNAME/bigbluebutton/api', script)

    def test_greenlight_environment_is_backed_up_before_change(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        self.assertIn('cp -a "$greenlight_env" "$greenlight_env.bcp-backup-', script)

    def test_effective_bbb_web_url_is_persistently_repaired(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        fixer = (ROOT / "provision" / "fix-public-url.sh").read_text(encoding="utf-8")
        self.assertIn("/usr/share/bbb-web/WEB-INF/classes/bigbluebutton.properties", fixer)
        self.assertIn("/usr/local/sbin/bcp-fix-public-url", script)
        self.assertIn("apply-config.sh", script)

    def test_real_join_redirect_is_validated(self):
        script = (ROOT / "provision" / "install.sh").read_text(encoding="utf-8")
        validator = (ROOT / "provision" / "verify-join-url.py").read_text(encoding="utf-8")
        self.assertIn("bcp-verify-join-url.py", script)
        self.assertIn('expected = f"{base}/html5client"', validator)
        self.assertIn("join redirect uses an unexpected origin", validator)


if __name__ == "__main__":
    unittest.main()
