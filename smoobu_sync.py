#!/usr/bin/env python3
"""
Smoobu to The Keys Synchronization Script

This script polls the Smoobu API for bookings and synchronizes
access codes with The Keys smart locks.

Usage:
    python smoobu_sync.py

Or set up as a cron job:
    */15 * * * * /usr/bin/python3 /path/to/smoobu_sync.py
"""

import sys
import yaml
import logging
import random
from datetime import datetime, timedelta
from typing import Dict, List, Optional
from pathlib import Path

from thekeys_api import TheKeysAPI
from smoobu_api import SmoobuAPI


class SmoobuSync:
    """Synchronizes Smoobu bookings with The Keys access codes"""
    
    def __init__(self, config_path: str = 'config.yaml'):
        self.config = self._load_config(config_path)
        self.stats = {
            'created': 0,
            'updated': 0,
            'deleted': 0,
            'skipped': 0,
            'errors': 0
        }
        self._setup_logging()
        self._init_apis()
    
    def _load_config(self, config_path: str) -> Dict:
        """Load configuration from YAML file"""
        config_file = Path(config_path)
        if not config_file.exists():
            raise FileNotFoundError(f"Config file not found: {config_path}")
        
        with open(config_file, 'r') as f:
            return yaml.safe_load(f)
    
    def _setup_logging(self):
        """Setup logging to file and console"""
        log_file = self.config.get('log_file', 'logs/sync.log')
        
        # Create log directory if needed
        Path(log_file).parent.mkdir(parents=True, exist_ok=True)
        
        # Configure logging
        logging.basicConfig(
            level=logging.INFO,
            format='[%(asctime)s] [%(levelname)s] %(message)s',
            handlers=[
                logging.FileHandler(log_file),
                logging.StreamHandler(sys.stdout)
            ]
        )
        self.logger = logging.getLogger(__name__)
    
    def _init_apis(self):
        """Initialize API clients"""
        # Initialize The Keys API
        thekeys_config = self.config['thekeys']
        self.thekeys = TheKeysAPI(
            thekeys_config['username'],
            thekeys_config['password']
        )
        
        # Set accessoire mappings
        for lock_id, accessoire_id in self.config['lock_accessoires'].items():
            self.thekeys.set_accessoire_mapping(int(lock_id), int(accessoire_id))
        
        # Login to The Keys
        self.logger.info("Logging in to The Keys...")
        self.thekeys.login()
        
        # Initialize Smoobu API
        smoobu_config = self.config['smoobu']
        self.smoobu = SmoobuAPI(smoobu_config['api_key'])
    
    def _generate_pin(self) -> str:
        """Generate random 4-digit PIN"""
        return str(random.randint(1000, 9999))
    
    def _get_lock_id(self, apartment_id: int) -> Optional[int]:
        """Get lock ID for apartment"""
        return self.config['apartment_locks'].get(str(apartment_id))
    
    def _sanitize_name(self, name: str) -> str:
        """Sanitize guest name"""
        import re
        # Remove special characters
        name = re.sub(r'[^\w\s\-\']', '', name)
        return name.strip()[:100]
    
    def _get_guest_name(self, booking: Dict) -> str:
        """Extract and format guest name from booking"""
        first_name = self._sanitize_name(booking.get('firstname', booking.get('firstName', '')))
        last_name = self._sanitize_name(booking.get('lastname', booking.get('lastName', '')))
        guest_name = f"{first_name} {last_name}".strip()
        
        # Prefix with "smoobu" to identify API-synced codes
        return f"smoobu {guest_name}" if guest_name else "smoobu Guest"
    
    def _normalize_date(self, date_str: str) -> str:
        """Normalize date to YYYY-MM-DD format"""
        if not date_str:
            return ''
        
        # Try to parse various date formats
        try:
            # Try "Jan 26, 2026" format
            dt = datetime.strptime(date_str, "%b %d, %Y")
            return dt.strftime("%Y-%m-%d")
        except:
            pass
        
        # Already in YYYY-MM-DD format or unparseable
        return date_str
    
    def _needs_update(self, existing_code: Dict, booking: Dict) -> bool:
        """Check if code dates need updating"""
        existing_start = self._normalize_date(existing_code.get('date_debut', ''))
        existing_end = self._normalize_date(existing_code.get('date_fin', ''))
        
        booking_start = booking.get('arrival', '')
        booking_end = booking.get('departure', '')
        
        return existing_start != booking_start or existing_end != booking_end
    
    def _sync_booking(self, booking: Dict):
        """Synchronize a single booking"""
        booking_id = booking.get('id', 'unknown')
        apartment_id = booking.get('apartment-id') or booking.get('apartment', {}).get('id')
        
        if not apartment_id:
            self.logger.warning(f"Skipping booking {booking_id}: No apartment ID")
            self.stats['skipped'] += 1
            return
        
        # Get lock for this apartment
        lock_id = self._get_lock_id(apartment_id)
        if not lock_id:
            self.logger.warning(f"Skipping booking {booking_id} (apartment {apartment_id}): No lock mapping")
            self.stats['skipped'] += 1
            return
        
        # Get guest info
        guest_name = self._get_guest_name(booking)
        check_in = booking.get('arrival')
        check_out = booking.get('departure')
        
        if not check_in or not check_out:
            self.logger.warning(f"Skipping booking {booking_id}: Missing dates")
            self.stats['skipped'] += 1
            return
        
        try:
            # Check if code already exists
            existing_code = self.thekeys.find_code_by_name(lock_id, guest_name)
            
            if existing_code:
                # Check if dates need updating
                if self._needs_update(existing_code, booking):
                    self.logger.info(f"Updating code for {guest_name} (Booking {booking_id})")
                    
                    # Delete old code
                    self.thekeys.delete_code(existing_code['id'])
                    
                    # Create new code with updated dates
                    pin_code = self._generate_pin()
                    self.thekeys.create_code(lock_id, {
                        'guestName': guest_name,
                        'startDate': check_in,
                        'endDate': check_out,
                        'startTime': self.config['default_times']['check_in'],
                        'endTime': self.config['default_times']['check_out'],
                        'code': pin_code,
                        'description': f"Smoobu booking {booking_id}"
                    })
                    
                    self.logger.info(f"✅ Updated code for {guest_name} - New PIN: {pin_code}")
                    self.stats['updated'] += 1
                else:
                    self.logger.debug(f"Code for {guest_name} already up-to-date")
                    self.stats['skipped'] += 1
            else:
                # Create new code
                self.logger.info(f"Creating code for {guest_name} (Booking {booking_id})")
                
                pin_code = self._generate_pin()
                self.thekeys.create_code(lock_id, {
                    'guestName': guest_name,
                    'startDate': check_in,
                    'endDate': check_out,
                    'startTime': self.config['default_times']['check_in'],
                    'endTime': self.config['default_times']['check_out'],
                    'code': pin_code,
                    'description': f"Smoobu booking {booking_id}"
                })
                
                self.logger.info(f"✅ Created code for {guest_name} - PIN: {pin_code}")
                self.stats['created'] += 1
        
        except Exception as e:
            self.logger.error(f"Error syncing booking {booking_id}: {str(e)}")
            self.stats['errors'] += 1
    
    def _cleanup_old_codes(self):
        """Clean up expired codes (for guests who checked out)"""
        self.logger.info("Checking for expired codes to clean up...")
        
        yesterday = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')
        
        for apartment_id, lock_id in self.config['apartment_locks'].items():
            try:
                codes = self.thekeys.get_lock_codes(int(lock_id))
                
                for code in codes:
                    name = code.get('nom', '')
                    end_date = code.get('date_fin', '')
                    
                    # Only clean up codes created by our sync (start with "smoobu")
                    if not name.startswith('smoobu '):
                        continue
                    
                    # Check if end date has passed
                    if end_date:
                        end_date_normalized = self._normalize_date(end_date)
                        
                        if end_date_normalized and end_date_normalized < yesterday:
                            self.logger.info(f"Deleting expired code: {name} (ended {end_date})")
                            self.thekeys.delete_code(code['id'])
                            self.stats['deleted'] += 1
            
            except Exception as e:
                self.logger.error(f"Error cleaning up codes for lock {lock_id}: {str(e)}")
                self.stats['errors'] += 1
    
    def sync(self) -> Dict:
        """Run synchronization"""
        self.logger.info("=" * 60)
        self.logger.info("Starting Smoobu to The Keys synchronization")
        self.logger.info("=" * 60)
        
        try:
            # Get bookings from Smoobu
            self.logger.info("Fetching bookings from Smoobu API...")
            bookings = self.smoobu.get_bookings_needing_codes()
            self.logger.info(f"Found {len(bookings)} bookings to process")
            
            # Sync each booking
            for booking in bookings:
                self._sync_booking(booking)
            
            # Clean up old codes
            self._cleanup_old_codes()
            
            # Print statistics
            self.logger.info("-" * 60)
            self.logger.info("Synchronization complete!")
            self.logger.info("Statistics:")
            self.logger.info(f"  Created: {self.stats['created']}")
            self.logger.info(f"  Updated: {self.stats['updated']}")
            self.logger.info(f"  Deleted: {self.stats['deleted']}")
            self.logger.info(f"  Skipped: {self.stats['skipped']}")
            self.logger.info(f"  Errors:  {self.stats['errors']}")
            self.logger.info("=" * 60)
            
            return {
                'success': True,
                'stats': self.stats
            }
        
        except Exception as e:
            self.logger.error(f"FATAL ERROR: {str(e)}", exc_info=True)
            
            return {
                'success': False,
                'error': str(e),
                'stats': self.stats
            }


def main():
    """Main entry point"""
    try:
        sync = SmoobuSync('config.yaml')
        result = sync.sync()
        
        sys.exit(0 if result['success'] else 1)
    
    except FileNotFoundError as e:
        print(f"ERROR: {e}")
        print("Please copy config.example.yaml to config.yaml and configure it.")
        sys.exit(1)
    
    except Exception as e:
        print(f"FATAL ERROR: {e}")
        sys.exit(1)


if __name__ == '__main__':
    main()
