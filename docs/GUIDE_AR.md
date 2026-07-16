# دليل UPS — ShipBridge

## إيه هي الحزمة؟

`mohamedhekal/shipbridge-ups` تربط Laravel بـ **UPS REST API** (OAuth2) عن طريق ShipBridge.

```
تطبيقك → ShipBridge → shipbridge-ups → UPS (onlinetools.ups.com)
```

---

## قبل ما تبدأ

1. سجّل تطبيق على [UPS Developer Portal](https://developer.ups.com/)
2. فعّل **Shipping** و **Tracking** APIs
3. احصل على:
   - `client_id` / `client_secret`
   - رقم حساب UPS (`account_number`)

للاختبار استخدم CIE:

```env
UPS_BASE_URL=https://wwwcie.ups.com
```

---

## التثبيت

```bash
composer require mohamedhekal/shipbridge mohamedhekal/shipbridge-ups
```

`.env`:

```env
SHIPBRIDGE_DRIVER=ups
UPS_BASE_URL=https://onlinetools.ups.com
UPS_CLIENT_ID=your-client-id
UPS_CLIENT_SECRET=your-client-secret
UPS_ACCOUNT_NUMBER=your-account
UPS_SERVICE_CODE=11
UPS_LABEL_IMAGE_FORMAT=GIF
```

> لو عندك access token جاهز: `UPS_TOKEN=...` (هيتخطى OAuth).

---

## ابعت شحنة

```php
use Hekal\ShipBridge\Facades\ShipBridge;
use Hekal\ShipBridge\DTOs\Address;
use Hekal\ShipBridge\DTOs\CreateShipmentRequest;
use Hekal\ShipBridge\DTOs\Parcel;
use Hekal\ShipBridge\Ups\UpsDriver;

$shipment = ShipBridge::driver('ups')->createShipment(new CreateShipmentRequest(
    origin: new Address('Warehouse', '1 Industrial', 'Cairo', 'EG', phone: '01011111111', postalCode: '11511'),
    destination: new Address('Customer', '12 Nile', 'Giza', 'EG', phone: '01000000000', state: 'GZ', postalCode: '12511'),
    parcels: [new Parcel(weightKg: 1.2, description: 'Goods')],
    reference: 'ORD-42',
    metadata: [
        'cod' => 100,
        'currency' => 'USD',
    ],
));

$shipment->trackingNumber;

// الليبل (GIF base64) في الرد الأصلي:
$labelBase64 = UpsDriver::extractLabelContents($shipment->raw);
```

**مهم:** رقم تليفون المستلم (`Address::$phone`) **إجباري** عند UPS.

---

## تتبع / ليبل / مرتجع

```php
ShipBridge::driver('ups')->track($shipment->trackingNumber);

// الليبل المنفصل غير متاح — بيرجع رابط التتبع العام:
ShipBridge::driver('ups')->label($shipment->trackingNumber);
// → https://www.ups.com/track?tracknum=...

ShipBridge::driver('ups')->createReturn(...);
```

---

## Troubleshooting

| رسالة | الحل |
|---|---|
| requires ShipTo phone | `Address::$phone` على المستلم |
| requires account_number | `UPS_ACCOUNT_NUMBER` |
| OAuth failed | راجع `CLIENT_ID` / `CLIENT_SECRET` |
| Invalid service | غيّر `UPS_SERVICE_CODE` حسب بلدك |

---

## English docs

See [`API.md`](API.md) and [`README.md`](../README.md).
