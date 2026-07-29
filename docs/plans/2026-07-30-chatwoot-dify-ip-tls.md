# Chatwoot, Dify, and IP TLS deployment

## Approved public endpoints

- AI CRM: `https://201.24.124.144`
- Chatwoot: `https://201.24.124.144:3000`
- Dify: `https://201.24.124.144:8080`

## Design

- Keep the existing native Nginx/PHP-FPM/PostgreSQL AI CRM deployment.
- Run pinned official Docker Compose releases: Chatwoot `v4.14.2` and Dify `1.15.0`.
- Bind application containers to loopback-only high ports; expose only Nginx on `443`, `3000`, and `8080`.
- Use one Let's Encrypt short-lived IP certificate for every TLS listener.
- Automate frequent certificate renewal and reload Nginx only after a successful renewal.
- Keep each vendor stack's PostgreSQL and Redis isolated to preserve supported upgrade paths.
- Add swap before starting the two container stacks.
- Do not place API tokens or passwords in this document or Git.

## Execution

1. Capture server configuration and data backups.
2. Install Docker Compose, swap support, and current Certbot.
3. Download and pin the official release configurations.
4. Generate secret environment files on the server with restrictive permissions.
5. Start Chatwoot, prepare its database, then verify its local health.
6. Start Dify and verify its local health.
7. Obtain the IP certificate through an HTTP-01 webroot challenge.
8. Configure and validate Nginx TLS listeners.
9. Open only public TCP ports `3000` and `8080` in UFW.
10. Set the non-secret AI CRM integration URLs; configure tokens later through the CRM UI.
11. Verify HTTPS, container health, restart policies, timers, and logs.

## Rollback

- Restore the saved Nginx and UFW configuration.
- Stop the `chatwoot` and `dify` Compose projects.
- Restore AI CRM configuration only if its URLs were changed.
- Preserve vendor volumes unless an explicit data rollback is required.
