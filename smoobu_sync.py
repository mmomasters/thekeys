#!/usr/bin/env python3
"""
Smoobu to The Keys Synchronization
Production-ready sync using complete REST API
"""
import yaml
import logging
import requests
import random
import re
import time
from datetime import datetime, timedelta
from typing import Dict, List, Optional
from TheKeysAPI import TheKeysAPI


class SmoobuSync:
    """Synchronize Smoobu bookings with The Keys access codes"""
    
    def __init__(self, config_file: str = "config.yaml"):
        # Load configuration
        with open(config_file, 'r') as f:
            self.config = yaml.safe_load(f)
        
        # Setup logging with UTF-8 encoding
        log_file = self.config.get('log_file', 'logs/sync.log')
        log_level = getattr(logging, self.config.get('log_level', 'INFO'))
        
        # Create handlers with UTF-8 encoding
        import sys
        file_handler = logging.FileHandler(log_file, encoding='utf-8')
        console_handler = logging.StreamHandler(sys.stdout)
        
        # Set encoding for console (Windows compatibility)
        if sys.platform == 'win32':
            try:
                sys.stdout.reconfigure(encoding='utf-8')
            except:
                pass  # Fallback if reconfigure fails
        
        # Configure logging
        logging.basicConfig(
            level=log_level,
            format='%(asctime)s - %(levelname)s - %(message)s',
            handlers=[file_handler, console_handler]
        )
        self.logger = logging.getLogger(__name__)
        
        # Initialize APIs
        self.keys_api = TheKeysAPI(
            self.config['thekeys']['username'],
            self.config['thekeys']['password']
        )
        self.smoobu_api_key = self.config['smoobu']['api_key']
        self.smoobu_base_url = "https://login.smoobu.com/api"
        
        # SMSFactor API configuration
        self.smsfactor_token = self.config.get('smsfactor', {}).get('api_token', '')
        self.smsfactor_base_url = "https://api.smsfactor.com"
        self.sms_recipients = self.config.get('smsfactor', {}).get('recipients', [])
        
    def get_smoobu_bookings(self, start_date: str = None, end_date: str = None) -> List[Dict]:
        """Get bookings from Smoobu API with pagination"""
        if not start_date:
            start_date = datetime.now().strftime('%Y-%m-%d')
        if not end_date:
            end_date = (datetime.now() + timedelta(days=90)).strftime('%Y-%m-%d')
        
        url = f"{self.smoobu_base_url}/reservations"
        headers = {
            "Api-Key": self.smoobu_api_key,
            "Cache-Control": "no-cache"
        }
        
        all_bookings = []
        seen_ids = set()
        page = 0
        consecutive_empty_pages = 0
        
        try:
            while True:
                params = {
                    "arrivalFrom": start_date,
                    "arrivalTo": end_date,
                    "page": page,
                    "pageSize": 100  # Get more per page
                }
                
                r = requests.get(url, headers=headers, params=params)
                r.raise_for_status()
                data = r.json()
                bookings = data.get('bookings', [])
                total_items = data.get('total_items', 0)
                
                if not bookings:
                    break
                
                # Detect duplicates (Smoobu bug - returns same bookings on multiple pages)
                new_bookings = 0
                for booking in bookings:
                    booking_id = booking.get('id')
                    if booking_id and booking_id not in seen_ids:
                        all_bookings.append(booking)
                        seen_ids.add(booking_id)
                        new_bookings += 1
                
                self.logger.info(f"  Retrieved page {page}: {len(bookings)} bookings ({new_bookings} new, total: {total_items})")
                
                # Stop if we have all items according to total_items
                if total_items > 0 and len(all_bookings) >= total_items:
                    self.logger.info(f"  Retrieved all {total_items} bookings, stopping")
                    break
                
                page += 1
                
                # Small delay between requests (not needed with good duplicate detection)
                # time.sleep(0.1)  # Removed for speed
                
                # Safety limit
                if page > 100:
                    self.logger.warning("Reached pagination safety limit of 100 pages")
                    break
                
            return all_bookings
        except Exception as e:
            self.logger.error(f"Failed to get Smoobu bookings: {e}")
            return []
    
    def generate_code(self, length: int = 4) -> str:
        """Generate random PIN code"""
        return ''.join([str(random.randint(0, 9)) for _ in range(length)])
    
    def send_pin_to_guest(self, booking: Dict, full_pin: str, apartment_name: str) -> bool:
        """Send PIN code to guest via Smoobu API with multilingual template"""
        booking_id = booking.get('id')
        guest_name = booking.get('guest-name', 'Guest')
        arrival_date = booking.get('arrival', '')
        departure_date = booking.get('departure', '')
        language = booking.get('language', 'en').lower()
        
        # Multilingual message templates
        messages = {
            'en': f"""Dear {guest_name},

- Main building "Jana z Kolna 19" code is 1 + KEY + 5687
- Lobby door code is 3256 + ENTER
- Apartment {apartment_name} door code is {full_pin} + BLUE BUTTON

Your apartment code will ONLY work between the check in and check out date and time.
Your check in: {arrival_date} from 15.00
Your check out: {departure_date} until 12.00

PARKING : A lot of parking spaces are located on the street near Kolna Apartments. Parking is free from 5pm to 8am and during weekends and holidays, pricing: https://spp.szczecin.pl/informacja/paid-parking-zone-pricing

In case of any issue, please feel free to call us +48 91 819 99 65

We wish you a very pleasant stay,
Kolna Apartments""",
            
            'de': f"""Lieber, Herr {guest_name},

- Hauptgebäude "Jana z Kolna 19" Code ist 1 + SCHLÜSSEL + 5687
- Lobby-Türcode ist 3256 + ENTER
- Der Türcode für das Apartment {apartment_name} lautet {full_pin} + BLAUE TASTE

Ihr Apartmentcode funktioniert NUR zwischen Check-in- und Check-out-Datum und -Uhrzeit.
Ihr Check-in: {arrival_date} ab 15.00 Uhr.
Ihr Check-out: {departure_date} bis 12.00 Uhr.

PARKING : Viele Parkplätze befinden sich auf der Straße in der Nähe der Kolna Apartments. Das Parken ist von 17:00 bis 08:00 Uhr sowie an Wochenenden und Feiertagen kostenlos, Preisliste: https://spp.szczecin.pl/informacja/SPP-Preisliste

Bei Problemen können Sie uns gerne unter +48 91 819 99 65 anrufen.

Wir wünschen Ihnen einen sehr angenehmen Aufenthalt,
Kolna Apartments""",
            
            'pl': f"""Pan, Pani {guest_name},

- Kod budynku głównego "Jana z Kolna 19" to 1 + KLUCZ + 5687
- Kod do recepcji to 3256 + ENTER
- Kod apartamentu {apartment_name} to {full_pin} + NIEBIESKI PRZYCISK

Twój kod apartamentu będzie działał TYLKO pomiędzy datą i godziną zameldowania i wymeldowania.
Twoje zameldowanie: {arrival_date} od 15.00
Twoje wymeldowanie: {departure_date} do 12.00

PARKING : Dużo miejsc parkingowych znajduje się przy ulicy pod Kolna Apartments. Parking jest bezpłatny od 17:00 do 8:00 oraz w weekendy i święta, cennik: https://spp.szczecin.pl/informacja/cennik-strefy-platnego-parkowania

W przypadku jakichkolwiek problemów prosimy o kontakt telefoniczny +48 91 819 99 65

Życzymy miłego pobytu,
Kolna Apartments"""
        }
        
        # Get message in guest's language (default to English)
        message = messages.get(language, messages['en'])
        
        # Use official Smoobu API endpoint for sending messages
        url = f"{self.smoobu_base_url}/reservations/{booking_id}/messages/send-message-to-guest"
        headers = {
            "Api-Key": self.smoobu_api_key,
            "Content-Type": "application/json"
        }
        
        # Multilingual subjects
        subjects = {
            'en': "Kolna Apartments access codes and information",
            'de': "Zugangscodes für die Kolna Apartments",
            'pl': "Kody dostępu do Kolna Apartments"
        }
        subject = subjects.get(language, subjects['en'])
        
        # According to https://docs.smoobu.com/#send-message-to-guest
        data = {
            "subject": subject,
            "messageBody": message
        }
        
        try:
            r = requests.post(url, headers=headers, json=data)
            if r.status_code in [200, 201]:
                self.logger.info(f"Sent PIN to {guest_name} ({language})")
                return True
            else:
                self.logger.warning(f"Failed to send message: {r.status_code}")
                return False
        except Exception as e:
            self.logger.error(f"Failed to send PIN: {e}")
            return False
    
    def send_sms_notification(self, booking: Dict, full_pin: str, apartment_name: str, action: str = "new") -> bool:
        """Send SMS notification via SMSFactor API"""
        if not self.smsfactor_token or not self.sms_recipients:
            self.logger.debug("SMS notifications disabled (no token or recipients)")
            return False
        
        guest_name = booking.get('guest-name', 'Guest')
        arrival = booking.get('arrival', '')
        departure = booking.get('departure', '')
        booking_id = booking.get('id', '')
        
        # Create SMS message based on action
        if action == "new":
            message = f"🔑 NEW BOOKING #{booking_id}\n{guest_name}\n{apartment_name}\n{arrival} → {departure}\nPIN: {full_pin}"
        elif action == "update":
            message = f"📝 UPDATED BOOKING #{booking_id}\n{guest_name}\n{apartment_name}\n{arrival} → {departure}\nPIN: {full_pin}"
        elif action == "cancel":
            message = f"❌ CANCELLED BOOKING #{booking_id}\n{guest_name}\n{apartment_name}\n{arrival} → {departure}"
        else:
            message = f"🔔 BOOKING #{booking_id}\n{guest_name}\n{apartment_name}\n{arrival} → {departure}\nPIN: {full_pin}"
        
        url = f"{self.smsfactor_base_url}/send"
        headers = {
            "Authorization": f"Bearer {self.smsfactor_token}",
            "Content-Type": "application/json"
        }
        
        success_count = 0
        for recipient in self.sms_recipients:
            data = {
                "to": recipient,
                "text": message,
                "sender": "KolnaApts"
            }
            
            try:
                r = requests.post(url, headers=headers, json=data)
                if r.status_code in [200, 201]:
                    self.logger.info(f"Sent SMS to {recipient} for booking {booking_id}")
                    success_count += 1
                else:
                    self.logger.warning(f"Failed to send SMS to {recipient}: {r.status_code} - {r.text}")
            except Exception as e:
                self.logger.error(f"Failed to send SMS to {recipient}: {e}")
        
        return success_count > 0
    
    def extract_pin_from_message(self, booking: Dict, lock_id: int) -> Optional[str]:
        """
        Extract PIN code from Smoobu welcome message
        Smoobu shows 6 digits: [2-digit prefix][4-digit code]
        The Keys API uses only the last 4 digits
        """
        # Get the expected prefix for this lock
        prefix = self.config.get('digicode_prefixes', {}).get(lock_id, '')
        
        # Extract from notice or message
        message = booking.get('notice', '') or ''
        message += ' ' + (booking.get('message', '') or '')
        
        # Look for 6-digit PIN patterns (prefix + 4 digits)
        patterns = [
            r'(?:code|pin|digicode)[\s:]+(\d{6})',  # "code: 181234"
            r'\b(\d{6})\b',  # Just 6 digits as word
        ]
        
        for pattern in patterns:
            match = re.search(pattern, message, re.IGNORECASE)
            if match:
                full_pin = match.group(1)
                # Check if it starts with the expected prefix
                if prefix and full_pin.startswith(prefix):
                    # Return last 4 digits (strip prefix)
                    self.logger.info(f"Extracted PIN from message: {full_pin} -> {full_pin[2:]}")
                    return full_pin[2:]
                elif len(full_pin) == 6:
                    # Return last 4 digits anyway
                    self.logger.info(f"Extracted 6-digit PIN: {full_pin} -> {full_pin[2:]}")
                    return full_pin[2:]
        
        # Fallback: try 4-digit pattern
        match = re.search(r'\b(\d{4})\b', message)
        if match:
            self.logger.info(f"Extracted 4-digit PIN: {match.group(1)}")
            return match.group(1)
        
        return None
    
    def sync_booking(self, booking: Dict) -> bool:
        """Sync a single booking to The Keys"""
        try:
            apartment_id = str(booking.get('apartment', {}).get('id'))
            
            # Get lock ID from mapping
            lock_id = self.config['apartment_locks'].get(apartment_id)
            if not lock_id:
                self.logger.warning(f"No lock mapping for apartment {apartment_id}")
                return False
            
            # Get accessoire STRING ID
            id_accessoire = self.config['lock_accessoires'].get(lock_id)
            if not id_accessoire:
                self.logger.warning(f"No accessoire mapping for lock {lock_id}")
                return False
            
            # Get booking details
            guest_name = booking.get('guest-name', 'Guest')
            arrival = booking.get('arrival')
            departure = booking.get('departure')
            booking_id = booking.get('id')
            
            if not arrival or not departure:
                self.logger.warning(f"Missing dates for booking {booking_id}")
                return False
            
            # Get times from config
            times = self.config.get('default_times', {})
            check_in_hour = times.get('check_in_hour', '15')
            check_in_min = times.get('check_in_minute', '0')
            check_out_hour = times.get('check_out_hour', '12')
            check_out_min = times.get('check_out_minute', '0')
            
            # Check if code already exists for this booking FIRST
            existing_codes = self.keys_api.list_codes(lock_id)
            existing = None
            existing_by_name_date = None
            
            for code_entry in existing_codes:
                desc = code_entry.get('description') or ''
                # Check by Smoobu# first
                if f"Smoobu#{booking_id}" in desc:
                    existing = code_entry
                    break
                # Also check by name + date (for ALL codes without Smoobu#)
                if not existing:  # Only if we haven't found Smoobu# match yet
                    code_name = code_entry.get('nom', '').lower().strip()
                    code_start = code_entry.get('date_debut')
                    if (code_name == guest_name.lower().strip() and 
                        code_start == arrival):
                        existing_by_name_date = code_entry
            
            # Use existing by Smoobu# OR by name+date match
            if existing or existing_by_name_date:
                match_code = existing or existing_by_name_date
                match_type = "Smoobu#" if existing else "name+date"
                # KEEP existing PIN code - don't change it!
                existing_pin = match_code.get('code')
                
                # Check if update is actually needed
                needs_update = False
                desc = match_code.get('description') or ''
                
                # Need to add Smoobu# description?
                if f"Smoobu#{booking_id}" not in desc:
                    needs_update = True
                    self.logger.info(f"Code {match_code['id']} needs Smoobu# description")
                
                # Check if dates changed
                if match_code.get('date_debut') != arrival or match_code.get('date_fin') != departure:
                    needs_update = True
                    self.logger.info(f"Code {match_code['id']} has date changes")
                
                if needs_update:
                    self.logger.info(f"Updating code for booking {booking_id} (Code ID: {match_code['id']}, matched by {match_type}, keeping PIN: {existing_pin})")
                    success = self.keys_api.update_code(
                        code_id=match_code['id'],
                        name=guest_name,
                        code=existing_pin,  # MUST provide existing PIN (API requires it)
                        date_start=arrival,
                        date_end=departure,
                        time_start_hour=check_in_hour,
                        time_start_min=check_in_min,
                        time_end_hour=check_out_hour,
                        time_end_min=check_out_min,
                        description=f"Smoobu#{booking_id}"
                    )
                    if success:
                        self.logger.info(f"[OK] Updated code for {guest_name} - added Smoobu#{booking_id}")
                        
                        # Send SMS notification for updated booking
                        apartment_name = booking.get('apartment', {}).get('name', 'your apartment')
                        prefix = self.config.get('digicode_prefixes', {}).get(lock_id, '')
                        full_pin = f"{prefix}{existing_pin}" if prefix else existing_pin
                        self.send_sms_notification(booking, full_pin, apartment_name, action="update")
                        
                        return True
                    else:
                        self.logger.error(f"[FAILED] Could not update code {match_code['id']}")
                        return False
                else:
                    self.logger.info(f"Code {match_code['id']} for {guest_name} is already up to date (skipped)")
                    return True
            else:
                # Create NEW code - generate PIN
                code_settings = self.config.get('code_settings', {})
                pin_length = code_settings.get('length', 4)
                pin_code = self.generate_code(pin_length)
                
                # Get prefix for this lock
                prefix = self.config.get('digicode_prefixes', {}).get(lock_id, '')
                full_pin = f"{prefix}{pin_code}" if prefix else pin_code
                
                self.logger.info(f"Creating NEW code for booking {booking_id}: {full_pin} (4-digit: {pin_code})")
                
                result = self.keys_api.create_code(
                    lock_id=lock_id,
                    id_accessoire=id_accessoire,
                    name=guest_name,
                    code=pin_code,
                    date_start=arrival,
                    date_end=departure,
                    time_start_hour=check_in_hour,
                    time_start_min=check_in_min,
                    time_end_hour=check_out_hour,
                    time_end_min=check_out_min,
                    description=f"Smoobu#{booking_id}"
                )
                if result:
                    self.logger.info(f"[OK] Created code {result['code']} for {guest_name}")
                    apartment_name = booking.get('apartment', {}).get('name', 'your apartment')
                    
                    # Send SMS notification for new booking
                    self.send_sms_notification(booking, full_pin, apartment_name, action="new")
                    
                    # Send message to future guests (including same-day arrivals)
                    if datetime.now().strftime('%Y-%m-%d') <= arrival:
                        self.logger.info(f"Sending PIN message to {guest_name} (arrival: {arrival})")
                        self.send_pin_to_guest(booking, full_pin, apartment_name)
                    else:
                        self.logger.info(f"Skipping message for {guest_name} (arrival {arrival} is in past)")
                    return True
            
            return False
            
        except Exception as e:
            self.logger.error(f"Failed to sync booking: {e}")
            return False
    
    def cleanup_cancelled_bookings(self, active_bookings: List[Dict]):
        """Delete codes for cancelled bookings (only if checkout has passed)"""
        try:
            # Get all active booking IDs from Smoobu
            active_booking_ids = {str(b.get('id')) for b in active_bookings if b.get('id')}
            today = datetime.now().strftime('%Y-%m-%d')
            
            # Check all locks for codes with Smoobu# but missing from active bookings
            deleted_count = 0
            for lock_id in self.config['lock_accessoires'].keys():
                codes = self.keys_api.list_codes(lock_id)
                
                for code in codes:
                    desc = code.get('description') or ''
                    date_end = code.get('date_fin')
                    
                    # Extract Smoobu booking ID from description
                    if 'Smoobu#' in desc and date_end:
                        import re
                        match = re.search(r'Smoobu#(\d+)', desc)
                        if match:
                            booking_id = match.group(1)
                            # Only delete if: booking cancelled AND checkout has passed
                            if booking_id not in active_booking_ids and date_end < today:
                                guest_name = code.get('nom', 'Unknown')
                                self.logger.info(f"Deleting code for cancelled booking {booking_id} ({guest_name}, ended {date_end})")
                                if self.keys_api.delete_code(code['id']):
                                    self.logger.info(f"[OK] Deleted code {code['id']} (cancelled)")
                                    deleted_count += 1
            
            if deleted_count > 0:
                self.logger.info(f"Cleaned up {deleted_count} cancelled bookings")
        except Exception as e:
            self.logger.error(f"Cancelled booking cleanup failed: {e}")
    
    def cleanup_old_codes(self, lock_id: int, days_old: int = 7):
        """Delete codes for bookings that ended more than X days ago"""
        try:
            cutoff_date = (datetime.now() - timedelta(days=days_old)).strftime('%Y-%m-%d')
            codes = self.keys_api.list_codes(lock_id)
            
            for code in codes:
                date_end = code.get('date_fin')
                if date_end and date_end < cutoff_date:
                    desc = code.get('description') or ''
                    if 'Smoobu#' in desc:  # Only delete Smoobu-managed codes
                        self.logger.info(f"Deleting old code {code['id']} (ended {date_end})")
                        if self.keys_api.delete_code(code['id']):
                            self.logger.info(f"[OK] Deleted code {code['id']}")
        except Exception as e:
            self.logger.error(f"Cleanup failed: {e}")
    
    def run(self, dry_run=False):
        """Run full synchronization"""
        mode = "DRY RUN MODE" if dry_run else "LIVE MODE"
        self.logger.info("="*70)
        self.logger.info(f"Starting Smoobu to The Keys synchronization - {mode}")
        self.logger.info("="*70)
        
        if dry_run:
            self.logger.info("[WARNING] DRY RUN: No changes will be made, only previewing actions")
        
        self.dry_run = dry_run
        
        # Login to The Keys
        if not self.keys_api.login():
            self.logger.error("Failed to login to The Keys API")
            return False
        
        self.logger.info("[OK] Logged in to The Keys API")
        
        # Get bookings
        bookings = self.get_smoobu_bookings()
        self.logger.info(f"Found {len(bookings)} bookings from Smoobu")
        
        # Sync each booking
        success_count = 0
        for booking in bookings:
            if self.sync_booking(booking):
                success_count += 1
        
        self.logger.info(f"[OK] Synced {success_count}/{len(bookings)} bookings")
        
        # Check for cancelled bookings (codes exist but booking gone from Smoobu)
        self.cleanup_cancelled_bookings(bookings)
        
        # Cleanup old codes for each lock
        for lock_id in self.config['lock_accessoires'].keys():
            self.cleanup_old_codes(lock_id)
        
        self.logger.info("="*70)
        self.logger.info("Synchronization complete!")
        self.logger.info("="*70)
        
        return True


if __name__ == "__main__":
    sync = SmoobuSync()
    sync.run()
