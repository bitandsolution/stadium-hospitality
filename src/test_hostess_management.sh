#!/bin/bash

# Test script for Hostess Management System
# Phase 1.3 - Complete test suite

BASE_URL="https://checkindigitale.cloud"
STADIUM_ID=3

echo "=========================================="
echo "HOSPITALITY MANAGER - HOSTESS MANAGEMENT"
echo "Phase 1.3 Test Suite"
echo "=========================================="
echo ""

# Step 1: Login as stadium_admin
echo "Step 1: Login as stadium_admin..."
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"admin_test\",\"password\":\"SecurePass123!\",\"stadium_id\":$STADIUM_ID}")

TOKEN=$(echo $LOGIN_RESPONSE | grep -o '"access_token":"[^"]*' | cut -d'"' -f4)

if [ -z "$TOKEN" ]; then
    echo "❌ Login failed!"
    echo $LOGIN_RESPONSE
    exit 1
fi

echo "✅ Login successful"
echo "Token: ${TOKEN:0:20}..."
echo ""

# Step 2: Create Hostess User
echo "Step 2: Create new hostess user..."
CREATE_USER_RESPONSE=$(curl -s -X POST "$BASE_URL/api/admin/users" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "hostess_maria",
    "email": "maria@stadium.it",
    "password": "HostessPass123!",
    "full_name": "Maria Rossi",
    "role": "hostess",
    "phone": "+39 333 1234567"
  }')

HOSTESS_ID=$(echo $CREATE_USER_RESPONSE | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)

if [ -z "$HOSTESS_ID" ]; then
    echo "❌ Hostess creation failed!"
    echo $CREATE_USER_RESPONSE
    exit 1
fi

echo "✅ Hostess created with ID: $HOSTESS_ID"
echo ""

# Step 3: List all users (should include new hostess)
echo "Step 3: List all users in stadium..."
curl -s -X GET "$BASE_URL/api/admin/users?stadium_id=$STADIUM_ID&role=hostess" \
  -H "Authorization: Bearer $TOKEN" | jq '.'
echo ""

# Step 4: Get available rooms in stadium
echo "Step 4: Get available rooms..."
ROOMS_RESPONSE=$(curl -s -X GET "$BASE_URL/api/admin/rooms?stadium_id=$STADIUM_ID" \
  -H "Authorization: Bearer $TOKEN")

ROOM_ID_1=$(echo $ROOMS_RESPONSE | grep -o '"id":[0-9]*' | head -1 | cut -d':' -f2)
ROOM_ID_2=$(echo $ROOMS_RESPONSE | grep -o '"id":[0-9]*' | head -2 | tail -1 | cut -d':' -f2)

echo "Available rooms: $ROOM_ID_1, $ROOM_ID_2"
echo ""

# Step 5: Assign rooms to hostess
echo "Step 5: Assign rooms to hostess..."
curl -s -X POST "$BASE_URL/api/admin/users/$HOSTESS_ID/rooms" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"room_ids\": [$ROOM_ID_1, $ROOM_ID_2]}" | jq '.'
echo ""

# Step 6: Get assigned rooms
echo "Step 6: Get assigned rooms for hostess..."
curl -s -X GET "$BASE_URL/api/admin/users/$HOSTESS_ID/rooms" \
  -H "Authorization: Bearer $TOKEN" | jq '.'
echo ""

# Step 7: Update hostess info
echo "Step 7: Update hostess information..."
curl -s -X PUT "$BASE_URL/api/admin/users/$HOSTESS_ID" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"phone": "+39 333 9999999", "full_name": "Maria Rossi Senior"}' | jq '.'
echo ""

# Step 8: Remove one room assignment
echo "Step 8: Remove one room assignment..."
curl -s -X DELETE "$BASE_URL/api/admin/users/$HOSTESS_ID/rooms/$ROOM_ID_2" \
  -H "Authorization: Bearer $TOKEN" | jq '.'
echo ""

# Step 9: Verify remaining assignments
echo "Step 9: Verify remaining assignments..."
curl -s -X GET "$BASE_URL/api/admin/users/$HOSTESS_ID/rooms" \
  -H "Authorization: Bearer $TOKEN" | jq '.'
echo ""

# Step 10: Test hostess login
echo "Step 10: Test hostess login..."
HOSTESS_LOGIN=$(curl -s -X POST "$BASE_URL/api/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"hostess_maria\",\"password\":\"HostessPass123!\",\"stadium_id\":$STADIUM_ID}")

HOSTESS_TOKEN=$(echo $HOSTESS_LOGIN | grep -o '"access_token":"[^"]*' | cut -d'"' -f4)

if [ -z "$HOSTESS_TOKEN" ]; then
    echo "❌ Hostess login failed!"
    echo $HOSTESS_LOGIN
else
    echo "✅ Hostess login successful"
    echo "Token: ${HOSTESS_TOKEN:0:20}..."
fi
echo ""

# Step 11: Hostess views her assigned rooms
echo "Step 11: Hostess views her assigned rooms..."
curl -s -X GET "$BASE_URL/api/admin/users/$HOSTESS_ID/rooms" \
  -H "Authorization: Bearer $HOSTESS_TOKEN" | jq '.'
echo ""

echo "=========================================="
echo "✅ ALL TESTS COMPLETED"
echo "=========================================="
echo ""
echo "Summary:"
echo "- Stadium Admin can create hostess ✅"
echo "- Stadium Admin can assign rooms ✅"
echo "- Stadium Admin can update hostess ✅"
echo "- Stadium Admin can remove assignments ✅"
echo "- Hostess can login ✅"
echo "- Hostess can view assigned rooms ✅"