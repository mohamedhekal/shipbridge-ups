# UPS REST API reference

Contract aligned with UPS Developer Kit REST APIs (OAuth2 + Ship + Track).

## Hosts

| Env | Base |
|---|---|
| Production | `https://onlinetools.ups.com` |
| CIE / Sandbox | `https://wwwcie.ups.com` |

## OAuth2

`POST /security/v1/oauth/token`

| Item | Value |
|---|---|
| Auth | Basic `base64(client_id:client_secret)` |
| Body | `grant_type=client_credentials` (form) |
| Response | `{ "access_token": "...", "expires_in": 3600 }` |

Set `UPS_TOKEN` to skip OAuth and send a pre-issued bearer token.

## Ship

`POST /api/shipments/{version}/ship` (default `v1`)

Headers:

| Header | Value |
|---|---|
| `Authorization` | `Bearer {access_token}` |
| `transId` | Unique transaction id (32 chars) |
| `transactionSrc` | `shipbridge` (configurable) |
| `Content-Type` | `application/json` |

Body (simplified):

```json
{
  "ShipmentRequest": {
    "Request": { "RequestOption": "nonvalidate" },
    "Shipment": {
      "Description": "ORD-42",
      "Shipper": { "...": "..." },
      "ShipTo": { "Phone": { "Number": "required" } },
      "ShipFrom": { "...": "..." },
      "PaymentInformation": {
        "ShipmentCharge": {
          "Type": "01",
          "BillShipper": { "AccountNumber": "A12345" }
        }
      },
      "Service": { "Code": "11" },
      "Package": {
        "Packaging": { "Code": "02" },
        "PackageWeight": {
          "UnitOfMeasurement": { "Code": "KGS" },
          "Weight": "1.5"
        }
      }
    },
    "LabelSpecification": {
      "LabelImageFormat": { "Code": "GIF" }
    }
  }
}
```

### Success

```json
{
  "ShipmentResponse": {
    "Response": { "ResponseStatus": { "Code": "1" } },
    "ShipmentResults": {
      "ShipmentIdentificationNumber": "SHIP-001",
      "PackageResults": {
        "TrackingNumber": "1Z999AA10123456784",
        "ShippingLabel": {
          "ImageFormat": { "Code": "GIF" },
          "GraphicImage": "<base64>"
        }
      }
    }
  }
}
```

**Label:** GIF/PDF base64 is in `PackageResults.ShippingLabel.GraphicImage` on create.
There is no separate label download in this driver — use `UpsDriver::extractLabelContents($result->raw)`
or `label()` for the public tracking URL.

### COD

Pass `metadata.cod` (and optional `metadata.currency`). Maps to `ShipmentServiceOptions.COD`.

## Track

`GET /api/track/v1/details/{inquiryNumber}`

Same auth + `transId` / `transactionSrc` headers.

Activity status `type` codes map via `status_map` (`M`, `P`, `I`, `O`, `D`, `RS`, `X`, …).

## Config env vars

| Variable | Purpose |
|---|---|
| `UPS_BASE_URL` | API host |
| `UPS_CLIENT_ID` / `UPS_CLIENT_SECRET` | OAuth |
| `UPS_TOKEN` | Optional bearer override |
| `UPS_ACCOUNT_NUMBER` | Billing account |
| `UPS_SERVICE_CODE` | Default service (11 = Standard) |
| `UPS_LABEL_IMAGE_FORMAT` | `GIF` or `PDF` |
| `UPS_TRANSACTION_SRC` | `transactionSrc` header |
