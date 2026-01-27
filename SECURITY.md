# Security Policy

## 🔐 Security Best Practices

This document outlines security considerations and best practices for deploying and maintaining The Keys + Smoobu integration.

## Credential Management

### ✅ DO:
- **Never commit `config.php`** to version control (it's in `.gitignore`)
- Store credentials in `config.php` with restrictive file permissions (e.g., `chmod 600`)
- Use strong, unique passwords for The Keys account
- Rotate credentials periodically (every 90 days recommended)
- Use different credentials for testing and production environments

### ❌ DON'T:
- Share your `config.php` file
- Use the same password across multiple services
- Store credentials in code comments or documentation
- Email or message credentials in plain text

## Webhook Security

### Signature Verification (Highly Recommended)

Always configure a webhook secret to verify requests are genuinely from Smoobu:

1. Generate a strong random secret:
   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

2. Add to `config.php`:
   ```php
   'smoobu_secret' => 'your_generated_secret_here',
   ```

3. Configure the same secret in Smoobu webhook settings

### HTTPS Only

- **Always use HTTPS** for your webhook endpoint in production
- Obtain an SSL certificate (Let's Encrypt is free)
- Never expose webhook endpoints over HTTP in production

### IP Whitelisting (Optional)

Consider restricting webhook access to Smoobu's IP addresses:

```apache
# Apache .htaccess example
<FilesMatch "smoobu_webhook.php">
    Order Deny,Allow
    Deny from all
    Allow from [Smoobu IP ranges]
</FilesMatch>
```

```nginx
# Nginx example
location ~ smoobu_webhook.php$ {
    allow [Smoobu IP range];
    deny all;
}
```

## File Permissions

Set appropriate file permissions on your server:

```bash
# Configuration (readable only by web server user)
chmod 600 config.php
chown www-data:www-data config.php

# Scripts (readable and executable)
chmod 755 *.php

# Logs directory (writable by web server)
chmod 755 logs
chown www-data:www-data logs
```

## Input Validation

The integration includes input validation for:
- ✅ Apartment ID format
- ✅ Date formats (YYYY-MM-DD)
- ✅ Date logic (checkout after checkin)
- ✅ Guest name sanitization (removes harmful characters)
- ✅ String length limits

## Session Security

- Session cookies are stored in system temp directory with unique filenames
- Cookie files are named using MD5 hash: `thekeys_session_{md5(username)}.txt`
- Consider implementing session cleanup for older cookie files

## Logging Security

### Log File Protection

Logs may contain sensitive information. Protect them:

```bash
# Restrict log file access
chmod 600 logs/webhook.log
chown www-data:www-data logs/webhook.log
```

### Log Rotation

Implement log rotation to prevent logs from growing indefinitely:

```bash
# Linux logrotate example
# Create file: /etc/logrotate.d/thekeys
/path/to/thekeys/logs/*.log {
    weekly
    rotate 4
    compress
    delaycompress
    missingok
    notifempty
}
```

### What's Logged

Currently logged information includes:
- Webhook events received
- Guest names
- PIN codes generated
- Apartment and lock IDs
- Timestamps of all operations
- Error messages

**Important:** Logs contain PIN codes. Treat log files as sensitive data.

## API Communication

### The Keys API Security

- All API communication uses HTTPS (SSL/TLS)
- Session cookies maintain authentication
- No API keys are exposed in URLs
- Form tokens (CSRF protection) are used for all mutations

### SSL/TLS Verification

The API wrapper currently disables SSL peer verification for compatibility:
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
```

**For production**, consider enabling verification if you have proper CA certificates installed:
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
```

## Error Handling

### Information Disclosure

The webhook endpoint returns appropriate HTTP status codes:
- `200 OK` - Success
- `400 Bad Request` - Invalid JSON
- `401 Unauthorized` - Invalid signature
- `500 Internal Server Error` - Processing error

Error messages are logged but generic messages are returned to clients to avoid information disclosure.

### Production Error Reporting

Disable detailed PHP error output in production:

```php
# php.ini or .htaccess
display_errors = Off
log_errors = On
error_log = /path/to/php-errors.log
```

## Monitoring & Alerts

### Health Checks

Use the provided `healthcheck.php` endpoint for monitoring:

```bash
# Example monitoring with curl
curl https://yourdomain.com/healthcheck.php
```

Set up automated monitoring to alert you if:
- Health check returns unhealthy status
- No webhook activity for extended period
- Disk space running low (logs directory)

### Recommended Monitoring

1. **Uptime Monitoring**: Ping healthcheck.php every 5 minutes
2. **Log Monitoring**: Alert on ERROR entries in webhook.log
3. **Resource Monitoring**: CPU, memory, disk space
4. **SSL Certificate Expiry**: Monitor certificate expiration

## Backup & Recovery

### Configuration Backup

- Backup `config.php` securely (encrypted storage recommended)
- Document all lock IDs and apartment mappings
- Keep emergency_swap.php accessible for quick recovery

### Incident Response

If credentials are compromised:

1. **Immediately** change The Keys password
2. Update `config.php` with new credentials
3. Rotate webhook secret
4. Update secret in Smoobu webhook settings
5. Review logs for suspicious activity
6. Audit all access codes on all locks

## Testing Security

### Before Production

- [ ] Test webhook signature verification
- [ ] Verify SSL/HTTPS is working
- [ ] Check file permissions
- [ ] Test with invalid data (should be rejected)
- [ ] Review error messages (no sensitive data leaked)
- [ ] Confirm logs are not publicly accessible
- [ ] Test healthcheck endpoint

### Penetration Testing

Consider testing for:
- SQL injection (not applicable - no database)
- XSS attacks (test interface only)
- CSRF attacks (webhook uses signature verification)
- Brute force attacks (rate limiting recommended)

## Rate Limiting (Recommended)

Implement rate limiting to prevent abuse:

```nginx
# Nginx example
limit_req_zone $binary_remote_addr zone=webhook:10m rate=10r/m;

location ~ smoobu_webhook.php$ {
    limit_req zone=webhook burst=5;
}
```

## Data Privacy (GDPR Compliance)

### Personal Data Handling

The integration processes:
- Guest names (first name + last name)
- Booking dates
- Generated PIN codes

### Data Retention

- PIN codes are valid only for booking duration
- Logs contain guest names and should be rotated/deleted periodically
- Session cookies should be cleaned up regularly

### Recommendations

1. Document data processing in your privacy policy
2. Implement log retention policy (e.g., 90 days)
3. Provide mechanism to delete guest data on request
4. Ensure The Keys account complies with their privacy policy

## Security Updates

### Staying Updated

- Monitor PHP security updates
- Keep server OS patched
- Update cURL library regularly
- Review Smoobu API changes

### Reporting Security Issues

If you discover a security vulnerability:

1. **Do not** open a public GitHub issue
2. Email the maintainer directly with details
3. Allow reasonable time for patch development
4. Coordinate disclosure timeline

## Compliance Checklist

Before production deployment:

- [ ] Config file permissions set correctly (600)
- [ ] Webhook secret configured in both systems
- [ ] HTTPS/SSL certificate installed and valid
- [ ] `.gitignore` prevents committing sensitive files
- [ ] Logs directory has appropriate permissions
- [ ] Error display disabled in production
- [ ] Health check monitoring configured
- [ ] Backup procedures documented
- [ ] Incident response plan created
- [ ] Log rotation configured
- [ ] Rate limiting implemented (if needed)

## Security Contacts

- **Project Issues**: GitHub Issues (for non-sensitive bugs)
- **Security Vulnerabilities**: Contact repository owner directly

---

**Last Updated**: January 2026  
**Version**: 1.0
