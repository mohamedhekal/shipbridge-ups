# ShipBridge · UPS


[![CI](https://github.com/mohamedhekal/shipbridge-ups/actions/workflows/tests.yml/badge.svg)](https://github.com/mohamedhekal/shipbridge-ups/actions)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Packagist](https://img.shields.io/packagist/v/mohamedhekal/shipbridge-ups.svg)](https://packagist.org/packages/mohamedhekal/shipbridge-ups)

**UPS** shipping driver for [ShipBridge](https://github.com/mohamedhekal/shipbridge) · Region: **Global** / **عالمي**

---

## بالعربي — في ٣ خطوات

### ١) ثبّت الحزمتين
```bash
composer require mohamedhekal/shipbridge mohamedhekal/shipbridge-ups
```

### ٢) حط مفاتيح UPS في `.env`
```env
SHIPBRIDGE_DRIVER=ups
UPS_CLIENT_ID=your-client-id
UPS_CLIENT_SECRET=your-client-secret
UPS_TOKEN=optional-access-token
UPS_BASE_URL=https://onlinetools.ups.com/api
```
> UPS يستخدم OAuth (`CLIENT_ID` / `CLIENT_SECRET`). حط `UPS_TOKEN` لو عندك access token جاهز.

### ٣) ابعت شحنة
```php
use Hekal\ShipBridge\Facades\ShipBridge;
use Hekal\ShipBridge\DTOs\Address;
use Hekal\ShipBridge\DTOs\CreateShipmentRequest;
use Hekal\ShipBridge\DTOs\Parcel;

$shipment = ShipBridge::driver('ups')->createShipment(new CreateShipmentRequest(
    origin: new Address('المخزن', 'شارع ١', 'القاهرة', 'EG'),
    destination: new Address('العميل', 'شارع النيل', 'الجيزة', 'EG', phone: '01000000000'),
    parcels: [new Parcel(weightKg: 1.2)],
    reference: 'ORD-42',
));

echo $shipment->trackingNumber;
```

تتبع / ليبل / مرتجع:
```php
ShipBridge::driver('ups')->track($shipment->trackingNumber);
ShipBridge::driver('ups')->label($shipment->id);
```

---

## English — Quick start

```bash
composer require mohamedhekal/shipbridge mohamedhekal/shipbridge-ups
```

```env
SHIPBRIDGE_DRIVER=ups
UPS_CLIENT_ID=your-client-id
UPS_CLIENT_SECRET=your-client-secret
UPS_TOKEN=optional-access-token
UPS_BASE_URL=https://onlinetools.ups.com/api
```

```php
ShipBridge::driver('ups')->createShipment(...);
ShipBridge::driver('ups')->track('TRACKING');
ShipBridge::driver('ups')->label('SHIPMENT_ID');
```

## How it fits

```
Your Laravel app
      │
      ▼
 ShipBridge  (one API for all carriers)
      │
      ▼
 shipbridge-ups  ← this package (UPS)
```

## Testing

```bash
composer install && composer test
```

## License

MIT © Mohamed Hekal
