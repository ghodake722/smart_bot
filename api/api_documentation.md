# Flattrade PiConnect REST API Documentation

This directory provides secure wrapper endpoints over Flattrade's `PiConnectAPI`. To interact with these scripts locally or remotely, you must pass the custom authentication header `Authorization` or `X-Auth-Token` matching the token in your database.

---

## 1. Place Order (POST)
**Endpoint:** `http://localhost/api/place_order.php`
**Method:** `POST`

### Headers:
- `Content-Type`: `application/json`
- `X-Auth-Token`: `MY_API_TEST_TOKEN_123`

### Sample Request Body (JSON):
```json
{
    "exch": "NSE",
    "tsym": "ACC-EQ",
    "qty": "50",
    "prc": "1400",
    "prd": "H",
    "trantype": "B",
    "prctyp": "LMT",
    "ret": "DAY"
}
```
*(Note: `uid` and `actid` are automatically injected by the backend based on your `.env` session).*

### Sample Response (Success):
```json
{
    "status": "success",
    "data": {
        "request_time": "14:14:10 26-05-2020",
        "stat": "Ok",
        "result": "20052600000103"
    }
}
```

---

## 2. Modify Order (PUT)
**Endpoint:** `http://localhost/api/modify_order.php`
**Method:** `PUT`

### Headers:
- `Content-Type`: `application/json`
- `X-Auth-Token`: `MY_API_TEST_TOKEN_123`

### Sample Request Body (JSON):
```json
{
    "exch": "NSE",
    "tsym": "ACC-EQ",
    "qty": "50",
    "prc": "1400",         
    "prctyp": "LMT",
    "ret": "DAY", 
    "norenordno": "123456789"
}
```

### Sample Response (Success):
```json
{
    "status": "success",
    "data": {
        "request_time": "14:14:10 26-05-2020",
        "stat": "Ok",
        "result": "20052600000103"
    }
}
```

---

## 3. Cancel Order (DELETE)
**Endpoint:** `http://localhost/api/cancel_order.php`
**Method:** `DELETE`

### Headers:
- `Content-Type`: `application/json`
- `X-Auth-Token`: `MY_API_TEST_TOKEN_123`

### Sample Request Body (JSON):
```json
{
    "norenordno": "123456789"
}
```
*(Alternatively, you can pass `?norenordno=123456789` in the query string instead of the JSON body).*

### Sample Response (Failure / Order Cannot be Cancelled):
```json
{
    "status": "error",
    "message": "Flattrade API Error",
    "data": {
        "request_time": "16:01:48 28-05-2020",
        "stat": "Not_Ok",
        "emsg": "Rejected : ORA:Order not found to Cancel"
    }
}
```

---

## Security Verification (Failed Validation Example)

If you attempt any of the endpoints above without the correct `X-Auth-Token` (e.g., trying to modify an order with `X-Auth-Token: INVALID_TOKEN` or completely omitting it), the API immediately aborts and returns a 401 response. This protects the endpoint from unauthorized executions even if the server port mapping is exposed.

### Sample Request (Missing/Invalid Header)
`POST http://localhost/api/place_order.php`
(Empty Headers)

### Sample Response:
```json
{
    "status": "error",
    "message": "Unauthorized: Missing API Token Header (Authorization or X-Auth-Token)."
}
```
