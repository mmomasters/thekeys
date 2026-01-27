"""
Smoobu API Client
Handles API calls to Smoobu to fetch bookings
"""

import requests
from typing import List, Dict, Optional
from datetime import datetime, timedelta


class SmoobuAPI:
    """Client for Smoobu API"""
    
    def __init__(self, api_key: str):
        self.api_key = api_key
        self.base_url = "https://login.smoobu.com/api"
    
    def _make_request(self, endpoint: str, method: str = "GET", data: Optional[Dict] = None) -> Dict:
        """Make API request to Smoobu"""
        url = f"{self.base_url}{endpoint}"
        
        headers = {
            "Api-Key": self.api_key,
            "Content-Type": "application/json",
            "Cache-Control": "no-cache"
        }
        
        if method == "GET":
            response = requests.get(url, headers=headers)
        elif method == "POST":
            response = requests.post(url, headers=headers, json=data)
        else:
            raise ValueError(f"Unsupported method: {method}")
        
        response.raise_for_status()
        return response.json()
    
    def get_apartments(self) -> List[Dict]:
        """Get all apartments"""
        result = self._make_request("/apartments")
        return result.get('apartments', [])
    
    def get_bookings(self, from_date: Optional[str] = None, to_date: Optional[str] = None, 
                     include_blocked: bool = False) -> List[Dict]:
        """
        Get bookings within date range
        
        Args:
            from_date: Start date (YYYY-MM-DD), defaults to 7 days ago
            to_date: End date (YYYY-MM-DD), defaults to 90 days in future
            include_blocked: Include blocked periods
        """
        # Default: get bookings from 7 days ago to 90 days in future
        if not from_date:
            from_date = (datetime.now() - timedelta(days=7)).strftime('%Y-%m-%d')
        if not to_date:
            to_date = (datetime.now() + timedelta(days=90)).strftime('%Y-%m-%d')
        
        params = {
            'from': from_date,
            'to': to_date,
            'includeBlocked': 1 if include_blocked else 0
        }
        
        endpoint = f"/reservations?{'&'.join(f'{k}={v}' for k, v in params.items())}"
        result = self._make_request(endpoint)
        
        return result.get('bookings', [])
    
    def get_active_bookings(self) -> List[Dict]:
        """Get active bookings (current and future)"""
        today = datetime.now().strftime('%Y-%m-%d')
        future = (datetime.now() + timedelta(days=90)).strftime('%Y-%m-%d')
        
        bookings = self.get_bookings(today, future)
        
        # Filter out cancelled bookings
        return [b for b in bookings if b.get('status', '').lower() not in ['canceled', 'cancelled']]
    
    def get_booking(self, booking_id: int) -> Dict:
        """Get booking by ID"""
        return self._make_request(f"/reservations/{booking_id}")
    
    def get_bookings_needing_codes(self) -> List[Dict]:
        """Get bookings that need codes (arriving soon or currently checked in)"""
        from_date = (datetime.now() - timedelta(days=1)).strftime('%Y-%m-%d')  # Include current guests
        to_date = (datetime.now() + timedelta(days=30)).strftime('%Y-%m-%d')
        
        bookings = self.get_bookings(from_date, to_date)
        
        # Filter active bookings only
        return [b for b in bookings 
                if b.get('status', 'confirmed').lower() in ['confirmed', 'inquiry', 'request']]
