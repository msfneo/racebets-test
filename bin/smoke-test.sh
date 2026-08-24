#!/usr/bin/env bash
#
# Walks the whole specification against a running API using nothing but curl,
# including a burst of genuinely parallel withdrawals through the web server.
#
# Usage: ./bin/smoke-test.sh [base-url]

set -euo pipefail

BASE="${1:-http://localhost:8080}"

bold() { printf '\n\033[1m%s\033[0m\n' "$1"; }
call() { curl -sS -X "$1" "$BASE$2" -H 'Content-Type: application/json' ${3:+-d "$3"}; }

# Pretty-print if jq is around, otherwise leave the JSON as it is.
pretty() { if command -v jq >/dev/null 2>&1; then jq "${1:-.}"; else cat; fi; }

bold "1. Health"
call GET /health | pretty

bold "2. Register a customer (bonus rate is assigned at random, 5-20%)"
EMAIL="smoke.$(date +%s).$RANDOM@example.com"
CUSTOMER=$(call POST /customers "$(printf '{
  "gender": "female",
  "first_name": "Anna",
  "last_name": "Schmidt",
  "country": "de",
  "email": "%s"
}' "$EMAIL")")
echo "$CUSTOMER" | pretty

if command -v jq >/dev/null 2>&1; then
    ID=$(echo "$CUSTOMER" | jq -r '.data.id')
    RATE=$(echo "$CUSTOMER" | jq -r '.data.bonus_percent')
else
    ID=$(echo "$CUSTOMER" | sed -n 's/.*"id": *\([0-9]*\).*/\1/p' | head -1)
    RATE='?'
fi
echo "customer id=$ID, bonus rate=${RATE}%"

bold "3. The same email a second time is rejected (409)"
call POST /customers "$(printf '{
  "gender":"female","first_name":"Anna","last_name":"Schmidt","country":"DE","email":"%s"
}' "$EMAIL")" | pretty

bold "4. Edit the details given on registration"
call PATCH "/customers/$ID" '{"last_name":"Schmidt-Weber","country":"AT"}' | pretty '.data | {last_name, country}'

bold "5. Three deposits of 100.00 — the third earns the bonus"
for i in 1 2 3; do
    call POST "/customers/$ID/deposits" '{"amount":"100.00"}' \
        | pretty "{deposit: .data.transaction.amount, bonus: .data.bonus.amount, balance: .data.customer.balance}"
done

bold "6. Bonus money cannot be withdrawn (422)"
call POST "/customers/$ID/withdrawals" '{"amount":"300.01"}' | pretty '.error | {code, message}'

bold "7. Withdrawing the full real balance is fine"
call POST "/customers/$ID/withdrawals" '{"amount":"300.00"}' \
    | pretty '.data.customer.balance'

bold "8. Ledger for this customer"
call GET "/customers/$ID/transactions" | pretty '.data.items | map({type, amount})'

bold "9. Twelve simultaneous withdrawals against a balance of 100.00"
BURST=$(call POST /customers "$(printf '{
  "gender":"male","first_name":"Race","last_name":"Condition","country":"MT","email":"burst.%s.%s@example.com"
}' "$(date +%s)" "$RANDOM")")
if command -v jq >/dev/null 2>&1; then
    BURST_ID=$(echo "$BURST" | jq -r '.data.id')
else
    BURST_ID=$(echo "$BURST" | sed -n 's/.*"id": *\([0-9]*\).*/\1/p' | head -1)
fi

call POST "/customers/$BURST_ID/deposits" '{"amount":"100.00"}' >/dev/null

# All twelve requests are launched at once; only five of 20.00 can be honoured.
for _ in $(seq 1 12); do
    curl -sS -o /dev/null -w '%{http_code}\n' \
        -X POST "$BASE/customers/$BURST_ID/withdrawals" \
        -H 'Content-Type: application/json' -d '{"amount":"20.00"}' &
done | sort | uniq -c | sed 's/^/  /'
wait

echo "  (201 = applied, 422 = refused for insufficient funds)"
call GET "/customers/$BURST_ID" | pretty '.data.balance'
echo "  The balance must be exactly 0.00 and never negative."

bold "10. Report — deposits and withdrawals per country and date, last 7 days"
call GET /reports/transactions | pretty '.data'

bold "Done."
