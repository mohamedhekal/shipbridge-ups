# ShipBridge · UPS

[![CI](https://github.com/mohamedhekal/shipbridge-ups/actions/workflows/tests.yml/badge.svg)](https://github.com/mohamedhekal/shipbridge-ups/actions)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/mohamedhekal/shipbridge-ups.svg)](https://packagist.org/packages/mohamedhekal/shipbridge-ups)

**UPS** shipping driver for [ShipBridge](https://github.com/mohamedhekal/shipbridge) · Region: **Global** / **عالمي**

Real UPS REST API: OAuth2 + Ship + Track (`onlinetools.ups.com`)

---

## بالعربي — في ٣ خطوات

### ١) ثبّت الحزمتين
```bash
composer require mohamedhekal/shipbridge mohamedhekal/shipbridge-ups
```

### ٢) حط مفاتيح UPS في `.env`
```env
SHIPBRIDGE_DRIVER=ups
UPS_BASE_URL=https://onlinetools.ups.com
UPS_CLIENT_ID=your-client-id
UPS_CLIENT_SECRET=your-client-secret
UPS_ACCOUNT_NUMBER=your-account
UPS_SERVICE_CODE=11
UPS_LABEL_IMAGE_FORMAT=GIF
```
> Sandbox: `UPS_BASE_URL=https://wwwcie.ups.com` — التفاصيل في [`docs/GUIDE_AR.md`](docs/GUIDE_AR.md).

### ٣) ابعت شحنة
```php
use Hekal\ShipBridge\Facades\ShipBridge;
use Hekal\ShipBridge\DTOs\Address;
use Hekal\ShipBridge\DTOs\CreateShipmentRequest;
use Hekal\ShipBridge\DTOs\Parcel;

$shipment = ShipBridge::driver('ups')->createShipment(new CreateShipmentRequest(
    origin: new Address('المخزن', 'شارع ١', 'Cairo', 'EG', phone: '01011111111'),
    destination: new Address('العميل', 'شارع النيل', 'Giza', 'EG', phone: '01000000000'),
    parcels: [new Parcel(weightKg: 1.2)],
    reference: 'ORD-42',
));

echo $shipment->trackingNumber;
```

**الليبل:** GIF/PDF base64 في `$shipment->raw` — استخدم `UpsDriver::extractLabelContents($shipment->raw)`.

---

## English — Quick start

```bash
composer require mohamedhekal/shipbridge mohamedhekal/shipbridge-ups
```

```env
SHIPBRIDGE_DRIVER=ups
UPS_CLIENT_ID=...
UPS_CLIENT_SECRET=...
UPS_ACCOUNT_NUMBER=...
```

```php
ShipBridge::driver('ups')->createShipment(...); // POST /api/shipments/v1/ship
ShipBridge::driver('ups')->track('1Z...');      // GET /api/track/v1/details/{id}
ShipBridge::driver('ups')->label('1Z...');      // UPS tracking URL (label in create raw)
```

See [`docs/API.md`](docs/API.md) for OAuth, payload fields, COD, and status codes.

## How it fits

```
Your Laravel app
      │
      ▼
 ShipBridge  (one API for all carriers)
      │
      ▼
 shipbridge-ups  ← this package (UPS REST OAuth2)
```

## Testing

```bash
composer install && composer test
```

---
## License

MIT © Mohamed Hekal

---

<p align="center">
  <img src="docs/assets/banner.png" alt="ShipBridge · ups" width="100%">
</p>
