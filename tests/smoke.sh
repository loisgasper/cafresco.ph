#!/usr/bin/env bash
# End-to-end API smoke test against a local server (php -S 127.0.0.1:8080 tests/router.php)
set -euo pipefail
B=${BASE:-http://127.0.0.1:8080/api}
T=$(mktemp -d)
MAILLOG=${MAILLOG:-.dev/uploads/mail.log}
req() { local jar=$1 m=$2 p=$3; shift 3; curl -s -b "$T/$jar" -c "$T/$jar" -X "$m" -H 'X-Requested-With: cafresco' "$@" "$B/$p"; }
post() { local jar=$1 p=$2 d=$3; req "$jar" POST "$p" -H 'Content-Type: application/json' -d "$d"; }
get() { req "$1" GET "$2"; }
j() { php -r '$d=json_decode(stream_get_contents(STDIN),true); $v=$d; foreach(explode(".",$argv[1]) as $k){ $v=$v[$k]??null; } if(isset($d["error"])) { fwrite(STDERR,"API error: ".$d["error"]."\n"); exit(1);} echo is_array($v)?json_encode($v):$v;' "$1"; }
ok() { echo "✓ $*"; }
U=$(printf %05d $((RANDOM % 100000)))

post admin auth/login '{"login":"admin@cafresco.test","password":"adminpass1"}' | j user.role >/dev/null && ok admin login
post admin admin/settings '{"gcash_name":"Cafresco","gcash_number":"09171234567","new_account_order_cap":"1500"}' >/dev/null
CAT=$(post admin admin/categories '{"name":"Coffee","sort":1,"active":true}' | j categories.0.id); ok "category $CAT"
G=$(post admin admin/addon-groups '{"name":"Milk","min_select":0,"max_select":1,"addons":[{"name":"Oat milk","price":30},{"name":"Regular","price":0}]}' | j addon_groups.0.id); ok "addon group $G"
post admin admin/products "{\"name\":\"Iced Latte $U\",\"category_id\":$CAT,\"base_price\":120,\"active\":true,\"variants\":[{\"name\":\"12oz\",\"price\":120},{\"name\":\"16oz\",\"price\":145}],\"addon_group_ids\":[$G]}" >/dev/null
MENU=$(get anon menu)
PID=$(echo "$MENU" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["products"] as $p) if(str_contains($p["name"],"'$U'")) { echo $p["id"]." ".$p["variants"][1]["id"]." ".$p["addon_groups"][0]["addons"][0]["id"]; }')
read PID VID AID <<<"$PID"; ok "menu has product $PID variant $VID addon $AID"
post admin admin/discounts "{\"name\":\"Promo $U\",\"code\":\"SAVE$U\",\"type\":\"percent\",\"value\":10,\"active\":true}" >/dev/null && ok discount
CO=$(post admin admin/rider-companies "{\"name\":\"Speedy $U\",\"base_rate\":\"50\",\"rate_note\":\"first 3 km\",\"active\":true}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["companies"] as $c) if(str_contains($c["name"],"'$U'")) echo $c["id"];')
get anon config | grep -q "Speedy $U" && ok "starting rate in public config"

# customer
post nosoc auth/register "{\"name\":\"No Social $U\",\"email\":\"nos$U@test.ph\",\"mobile\":\"0914${U}04\",\"password\":\"secret123\"}" | grep -q 'Facebook or Instagram' && ok "sign-up without Facebook or Instagram refused"
post cust auth/register "{\"name\":\"Ana $U\",\"email\":\"ana$U@test.ph\",\"mobile\":\"0918${U}00\",\"password\":\"secret123\",\"instagram_url\":\"@ana.$U\"}" | j user.instagram_url | grep -q "instagram.com/ana.$U/" && ok "customer registered with Instagram handle"
post cust auth/profile "{\"name\":\"Ana $U\",\"instagram_url\":\"\",\"facebook_url\":\"\"}" | grep -q 'Keep at least one' && ok "cannot remove both social links"
ITEMS="[{\"product_id\":$PID,\"variant_id\":$VID,\"addon_ids\":[$AID],\"qty\":2}]"
R=$(post cust orders "{\"items\":$ITEMS,\"fulfillment\":\"pickup\",\"payment_method\":\"gcash\"}"); echo "$R" | grep -q 'verify your email' && ok "unverified customer blocked"
TOKEN=$(grep -o 'verify-email/[a-f0-9]*' "$MAILLOG" | tail -1 | cut -d/ -f2)
post cust auth/verify-email "{\"token\":\"$TOKEN\"}" | j ok >/dev/null && ok email verified
Q=$(post cust cart/quote "{\"items\":$ITEMS,\"discount_code\":\"SAVE$U\"}"); [ "$(echo "$Q" | j total)" = "315" ] && ok "quote 2x(145+30) -10% = 315"
R=$(post cust orders "{\"items\":$ITEMS,\"fulfillment\":\"pickup\",\"payment_method\":\"pay_on_pickup\"}"); echo "$R" | grep -q 'unlocks after' && ok "pay on pickup blocked for new customer"
OID=$(post cust orders "{\"items\":$ITEMS,\"fulfillment\":\"pickup\",\"payment_method\":\"gcash\",\"discount_code\":\"SAVE$U\"}" | j order.id); ok "order $OID placed"
php -r '$i=imagecreatetruecolor(40,40); imagepng($i,"'$T'/p.png");'
REF="10${U}12345678"; REF=${REF:0:13}
req cust POST "orders/$OID/payment" -F "gcash_ref=$REF" -F "proof=@$T/p.png" | j order.status | grep -q payment_submitted && ok "proof uploaded"
[ "$(req admin GET files/proof/$OID -o /dev/null -w '%{http_code}')" = 200 ] && ok "staff can view proof"
[ "$(req anon GET files/proof/$OID -o /dev/null -w '%{http_code}')" = 401 ] && ok "anonymous cannot view proof"
for s in confirmed preparing ready completed; do post admin "admin/orders/$OID/status" "{\"status\":\"$s\"}" | j order.status >/dev/null; done; ok "order completed"
PTS=$(get cust auth/me | j user.points); [ "$PTS" = "6" ] && ok "points earned: $PTS"

# rider
post rider rider/register "{\"company_id\":$CO,\"name\":\"Rider $U\",\"email\":\"rider$U@test.ph\",\"mobile\":\"0919${U}00\",\"password\":\"secret123\",\"plate_no\":\"abc123\"}" | j user.rider.approval | grep -q pending && ok "rider registered (pending)"
RID=$(get rider auth/me | j user.id)
post admin "admin/riders/$RID" '{"approval":"approved"}' >/dev/null && ok rider approved
post rider rider/online '{"online":true}' | j user.rider.is_online >/dev/null && ok rider online
OID2=$(post cust orders "{\"items\":$ITEMS,\"fulfillment\":\"partner_rider\",\"payment_method\":\"pay_on_pickup\",\"delivery_address\":\"123 Rizal St, Brgy Uno\",\"delivery_lat\":14.6,\"delivery_lng\":121.0}" | j order.id); ok "rider order $OID2 placed"
post admin "admin/orders/$OID2/status" '{"status":"confirmed"}' >/dev/null
get rider rider/jobs | j available | grep -q "\"id\":$OID2" && ok "rider sees available job"
get rider rider/jobs | j available | grep -q '"customer_name":null' && ok "customer name hidden before rate accepted"
post rider "rider/jobs/$OID2/accept" '{"fee":30}' | grep -q 'starting rate' && ok "rate below company starting rate refused"
post rider "rider/jobs/$OID2/accept" '{"fee":80}' | j mine | grep -q '"quote_status":"pending"' && ok "rider sent rate 80"
get cust orders/$OID2 | j order.rider_quote_status | grep -q pending && ok "customer sees pending offer"
post rider "rider/jobs/$OID2/pickup" '{}' | grep -q 'accept your rate' && ok "pickup blocked until accepted"
post cust "orders/$OID2/rider-quote" '{"accept":false}' | j order.declined_count | grep -q 1 && ok "customer declined"
get rider rider/jobs | j available | grep -vq "\"id\":$OID2," && ok "declined rider no longer sees the job"
post rider2 rider/register "{\"company_id\":$CO,\"name\":\"Rider2 $U\",\"email\":\"rider2$U@test.ph\",\"mobile\":\"0917${U}01\",\"password\":\"secret123\"}" >/dev/null
RID2=$(get rider2 auth/me | j user.id); post admin "admin/riders/$RID2" '{"approval":"approved"}' >/dev/null; post rider2 rider/online '{"online":true}' >/dev/null
post rider2 "rider/jobs/$OID2/accept" '{"fee":60}' >/dev/null && ok "second rider sent rate 60"
post cust "orders/$OID2/rider-quote" '{"accept":true}' | j order.rider_quote_status | grep -q accepted && ok "customer accepted rate"
get rider2 rider/jobs | j mine | grep -q "Ana $U" && ok "rider sees customer details after acceptance"
post admin "admin/orders/$OID2/status" '{"status":"ready"}' >/dev/null
post rider2 "rider/jobs/$OID2/pickup" '{}' >/dev/null && post rider2 "rider/jobs/$OID2/deliver" '{}' >/dev/null
[ "$(get cust orders/$OID2 | j order.status)" = completed ] && ok "delivered & completed"
get cust notifications | grep -q 'Rider offer' && ok "customer notified of rider offer"
# facebook link, trusted customer, support chat
post cust3 auth/register "{\"name\":\"Bea $U\",\"email\":\"bea$U@test.ph\",\"mobile\":\"0916${U}02\",\"password\":\"secret123\",\"facebook_url\":\"facebook.com/bea.$U\"}" | j user.facebook_url | grep -q 'https://facebook.com/bea' && ok "facebook link saved"
post cust3 auth/register "{\"name\":\"X\",\"email\":\"x$U@test.ph\",\"mobile\":\"0915${U}03\",\"password\":\"secret123\",\"facebook_url\":\"http://evil.example/x\"}" | grep -q 'Facebook profile link' && ok "non-facebook link refused"
CID3=$(get cust3 auth/me | j user.id)
post admin "admin/customers/$CID3" '{"verify_email":true}' >/dev/null
post cust3 orders "{\"items\":$ITEMS,\"fulfillment\":\"pickup\",\"payment_method\":\"pay_on_pickup\"}" | grep -q 'unlocks after' && ok "untrusted new customer must pay GCash first"
post admin "admin/customers/$CID3" '{"trusted":true}' >/dev/null
get cust3 auth/me | j user.trusted | grep -q 1 && ok "customer marked trusted"
post cust3 orders "{\"items\":$ITEMS,\"fulfillment\":\"pickup\",\"payment_method\":\"pay_on_pickup\"}" | j order.status | grep -q pending_confirmation && ok "trusted customer can pay on pickup"
post cust3 chat '{"body":"Hi! Is the banana cake available today?"}' | j messages | grep -q 'banana cake' && ok "customer sent chat"
get admin admin/chats | j threads | grep -q "Bea $U" && ok "admin inbox shows thread"
get admin admin/feed | j chat_unread | grep -qv '^0$' && ok "admin feed counts unread chats"
get cust3 'chat?peek=1' | j unread | grep -q '^0$' && ok "no unread replies yet"
post admin "admin/chats/$CID3" '{"body":"Yes! Fresh batch at 3pm."}' >/dev/null && ok "admin replied"
get cust3 'chat?peek=1' | j unread | grep -q '^1$' && ok "customer sees 1 unread reply"
get cust3 chat | j messages | grep -q 'Fresh batch' && ok "customer reads reply"
get cust3 'chat?peek=1' | j unread | grep -q '^0$' && ok "reply marked seen"
post rider2 chat '{"body":"Rider here, gate code?"}' | j messages | grep -q 'gate code' && ok "rider can chat too"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{}' "$B/auth/logout"); echo "$R" | grep -q 'Bad request origin' && ok "CSRF header enforced"
echo "All smoke tests passed"
