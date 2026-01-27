# Smoobu to The Keys Integration (Python)

Python-based API polling solution for synchronizing Smoobu bookings with The Keys smart lock access codes.

## Features

- 🔄 **API Polling**: Periodically fetches bookings from Smoobu API
- 🔑 **Automatic Code Management**: Creates, updates, and deletes access codes
- 🔐 **Token Authentication**: Uses The Keys JWT API (more reliable than web scraping)
- 🧹 **Automatic Cleanup**: Removes expired codes after checkout
- 📝 **Detailed Logging**: Tracks all operations and errors
- ⏰ **Cron-Ready**: Designed to run as a scheduled task

## Installation

### Prerequisites

- Python 3.7 or higher
- pip (Python package manager)

### Setup

1. **Clone or download this repository**

2. **Install Python dependencies:**
   ```bash
   pip install -r requirements.txt
   ```

3. **Configure the integration:**
   ```bash
   # Copy the example config
   cp config.example.yaml config.yaml
   
   # Edit config.yaml with your credentials
   nano config.yaml
   ```

4. **Fill in your configuration:**
   - The Keys username (phone number) and password
   - Smoobu API key (from Smoobu Settings > API)
   - Apartment ID to Lock ID mappings
   - Lock ID to Accessoire (keypad) ID mappings

## Configuration

Example `config.yaml`:

```yaml
thekeys:
  username: "+33650868488"
  password: "your_password"

smoobu:
  api_key: "your_smoobu_api_key"

apartment_locks:
  "505200": 3723  # Map Smoobu apartment ID to The Keys lock ID

lock_accessoires:
  3723: 4413  # Map lock ID to accessoire (keypad) ID

default_times:
  check_in: "15:00"
  check_out: "12:00"

log_file: "logs/sync.log"
```

### Finding Your IDs

**The Keys Lock ID:**
1. Login to https://app.the-keys.fr
2. Go to your locks list
3. Click on a lock
4. Check the URL: `/compte/serrure/{LOCK_ID}/view_partage`

**The Keys Accessoire ID:**
1. Open a lock's page
2. Create a temporary code
3. Inspect the network tab to find the accessoire ID in the API request

**Smoobu Apartment ID:**
1. Login to Smoobu
2. Go to your apartments
3. Click on an apartment
4. Check the URL or apartment details

**Smoobu API Key:**
1. Login to Smoobu
2. Go to Settings > API
3. Generate or copy your API key

## Usage

### Manual Run

Run the synchronization manually:

```bash
python smoobu_sync.py
```

You should see output like:
```
[2026-01-27 15:00:00] [INFO] ============================================================
[2026-01-27 15:00:00] [INFO] Starting Smoobu to The Keys synchronization
[2026-01-27 15:00:00] [INFO] ============================================================
[2026-01-27 15:00:01] [INFO] Logging in to The Keys...
[2026-01-27 15:00:02] [INFO] Fetching bookings from Smoobu API...
[2026-01-27 15:00:03] [INFO] Found 5 bookings to process
[2026-01-27 15:00:04] [INFO] Creating code for smoobu John Doe (Booking 12345)
[2026-01-27 15:00:05] [INFO] ✅ Created code for smoobu John Doe - PIN: 1234
...
[2026-01-27 15:00:10] [INFO] Statistics:
[2026-01-27 15:00:10] [INFO]   Created: 2
[2026-01-27 15:00:10] [INFO]   Updated: 1
[2026-01-27 15:00:10] [INFO]   Deleted: 0
[2026-01-27 15:00:10] [INFO]   Skipped: 2
[2026-01-27 15:00:10] [INFO]   Errors:  0
```

### Automated Synchronization

Set up a cron job to run every 15-30 minutes:

```bash
# Edit crontab
crontab -e

# Add this line (runs every 15 minutes)
*/15 * * * * /usr/bin/python3 /path/to/thekeys/smoobu_sync.py
```

Or on Windows, use Task Scheduler:
1. Open Task Scheduler
2. Create Basic Task
3. Set trigger: Daily, repeat every 15 minutes
4. Action: Start a program
5. Program: `python`
6. Arguments: `C:\path\to\thekeys\smoobu_sync.py`

## How It Works

1. **Fetches Bookings**: Polls Smoobu API for active and upcoming bookings
2. **Syncs Codes**: For each booking:
   - Creates a new access code if one doesn't exist
   - Updates the code if dates have changed
   - Prefixes guest names with "smoobu" for identification
3. **Cleanup**: Removes expired codes (ended 1+ day ago) that were created by the sync
4. **Logs Everything**: All operations are logged to `logs/sync.log`

## Code Naming Convention

All codes created by this sync are prefixed with `smoobu` to distinguish them from manually created codes:
- Manual codes: `John Doe`, `Emergency`, etc.
- API-synced codes: `smoobu John Doe`, `smoobu Jane Smith`, etc.

Only codes with the `smoobu` prefix are automatically cleaned up after checkout.

## Troubleshooting

### "Config file not found"
Make sure you've copied `config.example.yaml` to `config.yaml` and configured it.

### "Failed to get authentication token"
Check your The Keys username and password in `config.yaml`.

### "Smoobu API error: HTTP 401"
Check your Smoobu API key in `config.yaml`.

### "No lock mapping found for apartment X"
Add the apartment ID to lock ID mapping in `config.yaml` under `apartment_locks`.

### "No accessoire mapping found for lock X"
Add the lock ID to accessoire ID mapping in `config.yaml` under `lock_accessoires`.

### Check Logs
All operations are logged to `logs/sync.log`. Check this file for detailed error messages.

## API Documentation

### The Keys API

This integration uses The Keys official API v2 with JWT token authentication:
- Base URL: `https://api.the-keys.fr`
- Authentication: JWT Bearer token
- Endpoints: `/api/v2/serrure/*`, `/api/v2/partage/accessoire/*`

### Smoobu API

- Base URL: `https://login.smoobu.com/api`
- Authentication: API Key header
- Documentation: https://docs.smoobu.com/

## Files

- `smoobu_sync.py` - Main synchronization script
- `thekeys_api.py` - The Keys API client
- `smoobu_api.py` - Smoobu API client
- `config.yaml` - Your configuration (not in git)
- `config.example.yaml` - Configuration template
- `requirements.txt` - Python dependencies
- `logs/sync.log` - Operation logs

## Migration from PHP Webhook

If you're migrating from the PHP webhook-based solution:

1. The Python version uses API polling instead of webhooks (more reliable)
2. Configuration uses YAML instead of PHP arrays
3. All booking codes are prefixed with "smoobu" for tracking
4. Run `python smoobu_sync.py` initially to sync existing bookings
5. Set up cron/scheduled task for automatic synchronization

## Security Notes

- ⚠️ Never commit `config.yaml` (contains credentials)
- 🔒 Keep your Smoobu API key secure
- 📝 Logs may contain guest names - protect them accordingly
- 🔐 The Keys token expires after a period - automatically refreshed

## Support

For issues or questions:
1. Check the logs in `logs/sync.log`
2. Review this README
3. Check the example configuration
4. Ensure all IDs are correctly mapped

## License

See main repository LICENSE file.
