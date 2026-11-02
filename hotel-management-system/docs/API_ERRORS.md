# API Error Handling

## Response shape

```json
{
  "success": false,
  "message": "Human-readable message",
  "code": "UNAUTHORIZED",
  "data": null
}
```

## Common codes

| Code | HTTP | Meaning |
|------|------|---------|
| UNAUTHORIZED | 401 | Not logged in / session expired |
| FORBIDDEN | 403 | Logged in but not admin |
| BAD_REQUEST | 400 | Invalid input |
| VALIDATION_ERROR | 422 | Field validation failed |
| NOT_FOUND | 404 | Resource missing |
| CONFLICT | 409 | Duplicate or FK conflict |
| METHOD_NOT_ALLOWED | 405 | Wrong HTTP method |
| DB_ERROR | 500 | Database failure |
| DB_CONNECTION_ERROR | 500 | Cannot connect to MySQL |
| SERVER_ERROR | 500 | Unexpected exception |
| INVALID_JSON | 400 | Malformed request body |
| NETWORK_ERROR | — | Client-side network failure |
| TIMEOUT | — | Client-side request timeout |

## Helpers (PHP)

```php
apiError('Message', 400, 'BAD_REQUEST');
apiSuccess('OK', $data);
requireMethod('POST');
requireFields($input, ['email', 'password']);
handleApiException($e);
```

## Client (JS)

```js
try {
  const data = await apiFetch('book.php', { method: 'POST', body: JSON.stringify(payload) });
} catch (err) {
  // err.message, err.code, err.status
  handleClientError(err);
}
```

Set `APP_DEBUG` to `false` in `api/config.php` for production (hides internal details).
