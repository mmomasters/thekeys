# The Keys Cloud - Smoobu Integration

**100% Python REST API Solution** - Complete CRUD operations for keypad access codes!

## 🎉 Major Breakthrough

After 200+ API tests, we discovered **ALL working endpoints**:

- ✅ **LIST** - Get all codes
- ✅ **CREATE** - Add new codes  
- ✅ **UPDATE** - Modify existing codes
- ✅ **DELETE** - Remove codes

**No PHP or web scraping needed!** Pure REST API integration.

## Features

- 🔄 Automatic sync of Smoobu bookings to The Keys access codes
- 🔐 Generates unique PIN codes for each booking
- ⏰ Configurable check-in/check-out times
- 🧹 Automatic cleanup of expired codes
- 📝 Comprehensive logging
- 🔧 Easy YAML configuration

## Quick Start

### 1. Install Dependencies

```bash
pip install -r requirements.txt
```

### 2. Configure

Copy the example config and fill in your details:

```bash
copy config.example.yaml config.yaml
```

Edit `config.yaml`:

```yaml
thekeys:
  username: "+33650868488"  # Your phone number
  password: "your_password"

smoobu:
  api_key: "your_smoobu_api_key"

apartment_locks:
  "505200": 3723   # Smoobu Apartment ID: Lock ID

lock_accessoires:
  3723: "OXe37UIa"   # Lock ID: STRING accessoire ID
  3728: "f4H7DpX0"   # IMPORTANT: Use STRING IDs!
  3735: "FBptKZHE"
```

### 3. Run Sync

```bash
python smoobu_sync.py
```

## API Endpoints Discovered

### LIST Codes
```python
GET /fr/api/v2/partage/all/serrure/{lock_id}?_format=json
```

### CREATE Code
```python
POST /fr/api/v2/partage/create/{lock_id}/accessoire/{id_accessoire_STRING}

Data (form-encoded):
- partage_accessoire[nom]: Guest name
- partage_accessoire[code]: PIN code
- partage_accessoire[date_debut]: Start date (YYYY-MM-DD)
- partage_accessoire[date_fin]: End date (YYYY-MM-DD)
- partage_accessoire[heure_debut][hour]: Check-in hour
- partage_accessoire[heure_debut][minute]: Check-in minute
- partage_accessoire[heure_fin][hour]: Check-out hour
- partage_accessoire[heure_fin][minute]: Check-out minute
```

### UPDATE Code
```python
POST /fr/api/v2/partage/accessoire/update/{code_id}

Data: Same structure as CREATE
```

### DELETE Code
```python
POST /fr/api/v2/partage/accessoire/delete/{code_id}

No data required
```

## Critical Discovery: STRING Accessoire IDs

⚠️ **IMPORTANT:** You **MUST** use STRING `id_accessoire` (NOT numeric `id`)!

**Correct:**
- `"OXe37UIa"` ✅
- `"f4H7DpX0"` ✅
- `"FBptKZHE"` ✅

**Wrong:**
- `4413` ❌
- `4383` ❌

Find STRING IDs by calling LIST endpoint and checking `accessoire.id_accessoire`.

## Using TheKeysAPI Class

```python
from TheKeysAPI import TheKeysAPI

# Initialize
api = TheKeysAPI(username="+33650868488", password="your_password")
api.login()

# List codes
codes = api.list_codes(lock_id=3723)

# Create code
result = api.create_code(
    lock_id=3723,
    id_accessoire="OXe37UIa",  # STRING ID!
    name="John Doe",
    code="1234",
    date_start="2026-02-01",
    date_end="2026-02-05"
)

# Update code
api.update_code(
    code_id=684395,
    name="Jane Doe",
    code="5678"
)

# Delete code
api.delete_code(code_id=684395)
```

## Configuration Details

### Finding Your IDs

**Lock ID:**
1. Login to https://app.the-keys.fr
2. Go to locks list
3. Click a lock
4. URL shows: `/compte/serrure/{LOCK_ID}/view_partage`

**Accessoire STRING ID:**
1. Use Python API:
```python
api = TheKeysAPI(username, password)
api.login()
codes = api.list_codes(3723)
print(codes[0]['accessoire']['id_accessoire'])  # "OXe37UIa"
```

**Smoobu Apartment ID:**
1. Login to Smoobu
2. Go to apartments
3. Click apartment
4. Check URL or details

**Smoobu API Key:**
1. Smoobu Settings > API
2. Generate/copy key

## Automation

Run sync automatically with cron/Task Scheduler:

**Linux/Mac (cron):**
```bash
# Every hour
0 * * * * cd /path/to/thekeys && python smoobu_sync.py
```

**Windows (Task Scheduler):**
- Create task to run `python C:\github\thekeys\smoobu_sync.py`
- Set trigger (e.g., every hour)

## Project Structure

```
thekeys/
├── TheKeysAPI.py          # Complete REST API client
├── smoobu_sync.py         # Production sync script
├── config.yaml            # Your configuration
├── config.example.yaml    # Configuration template
├── requirements.txt       # Python dependencies
├── README.md             # This file
└── logs/
    └── sync.log          # Sync logs
```

## API Discovery Journey

This solution was achieved through **200+ API endpoint tests**:

- Tested 50+ CREATE patterns → Found working pattern with STRING IDs
- Tested 100+ DELETE patterns → Found `/partage/accessoire/delete/{id}`
- Tested 50+ UPDATE patterns → Found `/partage/accessoire/update/{id}`

Key discoveries:
1. Must use **STRING** `id_accessoire` (not numeric)
2. Form-encoded data (not JSON)
3. Time requires `[hour]` and `[minute]` structure
4. Consistent `/partage/accessoire/{action}/{id}` pattern

## License

MIT

## Support

For issues or questions, check the logs in `logs/sync.log` for detailed error messages.

---

**Developed with 200+ API tests to crack The Keys Cloud REST API! 🎉**
