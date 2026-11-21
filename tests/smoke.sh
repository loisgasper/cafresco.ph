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
CO=$(post admin admin/rider-companies "{\"name\":\"Speedy $U\",\"active\":true}" | j companies.0.id)

# customer
post cust auth/register "{\"name\":\"Ana $U\",\"email\":\"ana$U@test.ph\",\"mobile\":\"0918${U}00\",\"password\":\"secret123\"}" | j user.id >/dev/null && ok customer registered
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
OID2=$(post cust orders "{\"items\":$ITEMS,\"fulfillment\":\"partner_rider\",\"payment_method\":\"pay_on_pickup\",\"delivery_address\":\"123 Rizal St, Brgy Uno\"}" | j order.id); ok "rider order $OID2 placed"
post admin "admin/orders/$OID2/status" '{"status":"confirmed"}' >/dev/null
get rider rider/jobs | j available | grep -q "\"id\":$OID2" && ok "rider sees available job"
get rider rider/jobs | j available | grep -q '"delivery_address":null' && ok "address hidden before accept"
post rider "rider/jobs/$OID2/accept" '{}' | j mine | grep -q 'Rizal' && ok "rider accepted, sees address"
post admin "admin/orders/$OID2/status" '{"status":"ready"}' >/dev/null
post rider "rider/jobs/$OID2/pickup" '{}' >/dev/null && post rider "rider/jobs/$OID2/deliver" '{}' >/dev/null
[ "$(get cust orders/$OID2 | j order.status)" = completed ] && ok "delivered & completed"
get cust notifications | grep -q 'Rider assigned' && ok "customer notified of rider"
R=$(curl -s -X POST -H 'Content-Type: application/json' -d '{}' "$B/auth/logout"); echo "$R" | grep -q 'Bad request origin' && ok "CSRF header enforced"
echo "All smoke tests passed"
