# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| 0.x     | :white_check_mark: |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

Report vulnerabilities via [GitHub Security Advisories](https://github.com/ojbaeza/station/security/advisories):

1. Go to the Security Advisories page
2. Click "Report a vulnerability"
3. Provide a detailed description of the vulnerability

## What to Expect

- **Initial Response:** Within 48 hours
- **Status Update:** Within 7 days
- **Resolution Timeline:** Depends on severity (critical issues prioritized)

## Disclosure Policy

- We follow responsible disclosure practices
- Security fixes will be released as patch versions
- Public disclosure occurs after a fix is available
- Credit will be given to reporters (unless anonymity is requested)

## Security Best Practices

When using Station in production, follow these guidelines:

### Credentials

- Never use default credentials (`station/station`) in production
- Generate strong, unique passwords for RabbitMQ
- Rotate credentials periodically
- Use environment variables for all sensitive configuration

### API Security

- Always set a secure `STATION_API_TOKEN` for API access
- Generate tokens using a cryptographically secure method:
  ```bash
  # Generate a secure 32-character token
  php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
  ```
- Use HTTPS for all dashboard and API access
- Implement proper authentication middleware
- Rotate API tokens periodically (recommended: every 90 days)

### Data Protection

- Enable checkpoint encryption: `STATION_CHECKPOINT_ENCRYPT=true`
- Use `ShouldBeEncrypted` for jobs containing sensitive data
- Implement `ShouldMaskPayload` to hide sensitive data in the dashboard
- Review job payloads to ensure no PII is logged unnecessarily

### Network Security

- Restrict RabbitMQ access to application servers only
- Use TLS for RabbitMQ connections in production
- Place the dashboard behind authentication middleware
- Consider IP allowlisting for management interfaces

### Monitoring

- Enable audit logging: `STATION_AUDIT_ENABLED=true`
- Monitor failed authentication attempts
- Set up alerts for unusual activity patterns
- Regularly review access logs

## Scope

This security policy applies to:

- The Station package (`ojbaeza/station`)
- Official Station documentation
- The Station dashboard and API

Third-party integrations, forks, and extensions are not covered by this policy.
