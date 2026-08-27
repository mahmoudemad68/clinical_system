# 1. الصورة النهائية للنظام

عندنا **4 واجهات** وليس 3:

```text
1. Patient Mobile Application
   Flutter — Android + iOS

2. Doctor Desktop Application
   Flutter Desktop

3. Pharmacy Desktop Application
   Flutter Desktop

4. Admin Dashboard
   React + TypeScript
```

وكلهم يتعاملوا مع Core Backend واحدة:

```text
                        ┌─────────────────┐
                        │ React Admin     │
                        └────────┬────────┘
                                 │
┌────────────────┐               │             ┌──────────────────┐
│ Patient Flutter│───────────────┼─────────────│ Doctor Flutter   │
└────────────────┘               │             └──────────────────┘
                                 │
                        ┌────────▼─────────┐
                        │ API Gateway/LB   │
                        └────────┬─────────┘
                                 │
                        ┌────────▼─────────┐
                        │ Laravel Core     │
                        │ Modular Monolith │
                        └───┬─────┬────┬───┘
                            │     │    │
                    ┌───────┘     │    └────────┐
                    ▼             ▼             ▼
               PostgreSQL       Redis        Reverb
               + PostGIS                    WebSockets
                    │
                    │
        ┌───────────┴───────────┐
        ▼                       ▼
       S3                  Python/FastAPI
 Medical Files                AI Layer
                                  │
                           ┌──────┴──────┐
                           ▼             ▼
                        Qdrant          LLM
```

Flutter رسميًا يدعم Android/iOS وكذلك Windows/macOS/Linux desktop، وبالتالي اختيار Flutter للـPatient + Doctor + Pharmacy يقلل عدد الـcodebases مع استمرار إمكانية الوصول إلى native APIs/plugins عند الحاجة. ([مستندات فلاتر][2])

---

# 2. Architectural Style

لن نبدأ Microservices.

هنستخدم:

**Modular Monolith + Separate AI Service.**

Laravel Project واحدة، لكن داخليًا مقسمة Domains مستقلة:

```text
Auth
Identity
Patients
Doctors
Clinics
Appointments
Queue
Clinical
Prescriptions
Labs
Pharmacies
MedicationCatalog
Inventory
POS
Purchasing
Chat
Notifications
AI
KnowledgeBase
Admin
Audit
Analytics
Integrations
```

كل Module له:

```text
Domain
Application
Infrastructure
HTTP/API
Events
Jobs
Policies
Tests
```

ممنوع Modules تبقى spaghetti وتعدل Tables بعضها مباشرة بدون قواعد.

الميزة هنا إننا نحصل على سهولة الـMonolith حاليًا، لكن لو Pharmacy أو Notifications أو AI احتاجت لاحقًا Service مستقلة، حدود الـmodule موجودة بالفعل.

---

# 3. Technology Stack النهائي

| Layer                        | Technology                             |
| ---------------------------- | -------------------------------------- |
| Patient Mobile               | Flutter / Dart                         |
| Doctor Desktop               | Flutter Desktop                        |
| Pharmacy Desktop             | Flutter Desktop                        |
| Admin                        | React + TypeScript                     |
| Backend                      | Laravel 13                             |
| High-performance PHP runtime | Laravel Octane + FrankenPHP            |
| REST API                     | Laravel                                |
| Authentication               | Laravel Sanctum + device/session layer |
| Database                     | PostgreSQL                             |
| Geographic queries           | PostGIS                                |
| Cache                        | Redis                                  |
| Queues                       | Redis + Laravel Horizon                |
| Realtime                     | Laravel Reverb + Redis                 |
| AI API                       | Python + FastAPI                       |
| Vector DB                    | Qdrant                                 |
| Dense/Sparse embeddings      | BGE-M3 baseline                        |
| Reranker                     | bge-reranker-v2-m3 baseline            |
| Medical files                | S3-compatible Object Storage           |
| Local desktop/mobile DB      | SQLite via Drift                       |
| Push notifications           | Firebase Cloud Messaging               |
| SMS                          | Provider abstraction — OTP only        |
| Maps                         | Google Maps integration                |
| API docs                     | OpenAPI                                |
| Containers                   | Docker                                 |
| Reverse Proxy / LB           | Nginx / managed LB                     |
| Metrics                      | Prometheus + Grafana                   |
| Logs                         | Loki or equivalent                     |
| Error tracking               | Sentry                                 |
| Tracing                      | Laravel Telescope (local) + W3C `traceparent` echo |
| CI/CD                        | GitHub Actions                         |

Laravel Octane يسمح بتشغيل Laravel على long-lived workers بدل bootstrap كامل لكل request، وFrankenPHP مدعوم رسميًا بواسطة Octane. ([Laravel][3])

---

# 4. Flutter Architecture

مش هنعمل 3 Projects بدون أي مشاركة للكود.

الأفضل Flutter monorepo:

```text
/apps
    patient_app/
    doctor_desktop/
    pharmacy_desktop/

/packages
    api_client/
    authentication/
    design_system/
    common_models/
    networking/
    realtime/
    secure_storage/
    local_database/
    localization/
    notifications/
    error_handling/
```

ونستخدم **Melos** لإدارة الـmonorepo.

داخل كل App:

```text
features/
   appointments/
      data/
      domain/
      presentation/

   medical_record/
   prescriptions/
   chat/
   ai/
```

### Flutter libraries

أرشح:

```text
Riverpod
→ state management / dependency injection

Dio
→ HTTP

go_router
→ navigation

Freezed + json_serializable
→ DTO/models

Drift + SQLite
→ local persistence

flutter_secure_storage
→ secrets/tokens

firebase_messaging
→ Push

intl
→ Arabic/English
```

ما نخزنش Business Rules خطيرة في Flutter.

مثلاً:

```text
هل الدكتور مسموح له يشوف Medical Record؟
```

Flutter ممكن يخفي الزر، لكن **Laravel هو اللي يحسم القرار**.

---

# 5. React Admin Architecture

```text
React
TypeScript
Vite
TanStack Query
React Router
React Hook Form
Zod
MUI
i18next
Apache ECharts
```

TanStack Query يمسك:

```text
server state
caching
refetch
mutations
pagination
```

وما نستخدمش Redux إلا لو ظهر فعلًا use case يحتاج global client state معقد.

Admin Authentication يكون **secure HTTP-only cookie/session** وليس token في localStorage.

Laravel Sanctum نفسه مصمم لدعم SPA authentication وكذلك mobile token authentication. ([Laravel][4])

---

# 6. Identity Model

يكون عندنا User مركزي:

```text
users
-----
id UUID
name
phone
password_hash
account_type
status
language
created_at
```

والـprofiles مستقلة:

```text
patient_profiles
doctor_profiles
pharmacy_organizations
clinic_staff_profiles
```

يعني User مش هو Patient.

ممكن يكون:

```text
User Account
     │
     └── Patient Profile
```

لكن Patient Profile ممكن يعيش **بدون User Account**.

وده مهم للمريض اللي الدكتور أضافه يدويًا.

---

# 7. National ID

الرقم القومي المصري:

```text
14 digits
```

لكن **مش هنخزنه plain text فقط**.

نخزن:

```text
national_id_encrypted
national_id_hash
```

`national_id_hash`:

```text
HMAC-SHA256(normalizedNationalId, serverSecret)
```

يستخدم في:

```text
matching
uniqueness
lookup
```

والقيمة الحقيقية encrypted.

نعمل:

```text
UNIQUE(national_id_hash)
```

عشان مستحيل يتعمل Patient Profile لنفس الرقم مرتين.

---

# 8. Patient Registration

المريض يدخل:

```text
Name
Phone
Password
Gender
National ID
Height
Weight
Marital Status
Blood Type optional
```

بعدها:

```text
Register
↓
Validate
↓
Check National ID
↓
Create pending account
↓
Send SMS OTP
↓
Verify
↓
Activate
```

OTP فقط SMS.

Notifications الأخرى Push.

---

# 9. Existing Patient Matching

لو الدكتور عمل Patient Profile لمريض بدون App:

```text
patient_profile.user_id = NULL
national_id_hash = XYZ
```

بعد شهور المريض سجل بنفس National ID:

```text
Registration
↓
national_id_hash exists?
      │
      YES
      ↓
existing profile has user?
      │
      NO
      ↓
SMS verification
      ↓
Attach new user_id
      ↓
NO new Medical Record
```

وبالتالي كل زياراته القديمة تظهر له فورًا.

---

# 10. Doctor Registration

لدينا طريقين.

### Self Registration

```text
Doctor registers
↓
uploads:
National ID
Syndicate Card
Specialty
↓
status = pending_review
↓
Admin
↓
Approve / Reject
```

قبل Approve:

```text
ممنوع clinical access
```

### Admin-created

Admin يقدر يعمل Doctor Account بعد التحقق يدويًا.

---

# 11. Pharmacy Registration

نفس الفكرة:

```text
Pharmacy registration
↓
organization data
↓
branch data
↓
legal/configurable documents
↓
Pending
↓
Admin approve/reject
```

الصيدلية organization مستقلة عن الفروع:

```text
Pharmacy Organization
        │
        ├── Branch A
        ├── Branch B
        └── Branch C
```

---

# 12. Access Control

عندنا:

```text
Admin
Doctor
Secretary/Receptionist
Patient
Pharmacy Owner/Account
```

Admin حاليًا Role واحدة.

لكن **Full Admin لا تعني Clinical Data Access**.

---

# 13. Doctor Medical Record Access

دي Rule أساسية.

الدكتور لا يستطيع:

```text
Search National ID
→ Open full medical history
```

ممنوع.

لازم:

```text
Appointment
↓
Patient attended
↓
Checked-in
↓
Medical Record Access granted
```

الـauthorization تكون contextual:

```text
doctor_id
patient_id
appointment_id
status
location
```

بعد انتهاء الزيارة:

```text
Full cross-doctor medical history access
→ revoked
```

لكن الدكتور يفضل قادر يشوف:

```text
His own encounters with that patient
His own diagnoses
His own prescriptions
His own tests
His own notes
```

لو المريض حجز عنده مرة أخرى وحضر:

```text
Full history access restored
during current visit.
```

---

# 14. Secretary Access

Secretary تشوف:

```text
Patient name
Phone
Basic demographics
Appointments
Queue
Locations
Booking data
```

ولا تشوف:

```text
Diagnosis
Medication
Allergies
Labs
Medical History
Prescription
Doctor Notes
```

---

# 15. Patient Medical Record

مصدر الحقيقة:

**PostgreSQL فقط.**

يحتوي:

```text
Demographics
Blood type
Height
Weight
Marital status

Chronic diseases
Allergies
Current medications
Past operations
Previous diagnoses
Previous visits
Prescriptions
Lab requests
Lab results
Uploaded medical files
Medical reports
Referrals
```

Patient:

```text
READ clinical data
NO EDIT clinical data
```

Doctor:

```text
WRITE medical data
only within authorized context
```

---

# 16. Encounter / Visit

كل كشف = Entity مستقلة.

```text
Encounter
---------
id
patient_id
doctor_id
appointment_id
clinic_location_id
started_at
completed_at
status
```

داخلها:

```text
History
Chief Complaint
Symptoms
Examination
Diagnosis
Notes
Requested Tests
Prescription
Follow-up
```

Diagnosis حاليًا:

```text
Free Text
```

مش ICD mandatory.

نقدر نضيف coding مستقبلًا بدون تغيير الـfree-text record.

---

# 17. Encounter Lifecycle

```text
Scheduled
↓
Checked In
↓
Waiting
↓
Start Consultation
↓
In Consultation
↓
End Consultation
↓
Completed
```

عند `Start Consultation`:

```text
create encounter
grant medical-record access
set current_patient
broadcast realtime event
```

عند `End Consultation`:

```text
finalize encounter
close current consultation
remove full history access
move queue
broadcast event
schedule notifications
open 48h chat
```

---

# 18. Doctor Dashboard

أهم Widget:

# Current Patient

يظهر:

```text
Name
Age/Gender
Appointment Type
Location
Visit Started At
Warnings
Allergies
Current Medication
```

Quick actions:

```text
Open Record
Prescription
Request Lab
Ask AI
Complete Consultation
```

تحتها:

```text
Waiting Queue
Today's Appointments
Completed
Upcoming
Pending Lab Results
Follow-ups
```

---

# 19. Doctor Locations

Doctor:

```text
1..N Clinic Locations
```

كل Location له:

```text
coordinates
address
schedule
appointment types
pricing
duration
staff
```

لا يوجد Schedule واحدة للدكتور كله.

---

# 20. Doctor Schedule

مثال:

```text
Clinic A

Saturday:
10:00 → 16:00

Monday:
14:00 → 22:00
```

وعندنا Exceptions:

```text
Vacation
Holiday
Blocked slot
Emergency closure
Special working day
```

---

# 21. Appointment Types

مبدئيًا:

```text
Physical Examination
Follow-up
Consultation
```

كل واحد:

```text
price
duration
location
active/inactive
```

مثال:

```text
Physical:
30 minutes
400 EGP

Follow-up:
15 minutes
200 EGP
```

Money يتخزن:

```text
integer minor units
```

مش float.

---

# 22. Appointment Availability

ما نولدش ملايين Slots في DB لسنين قدام.

نخزن:

```text
schedule rules
existing appointments
exceptions
```

ولما المستخدم يطلب availability:

```text
Schedule
-
Blocked periods
-
Existing appointments
=
Available slots
```

ونعمل Cache للأيام القريبة.

---

# 23. Booking

Patient:

```text
Find Doctor
↓
Choose Location
↓
Choose Appointment Type
↓
Choose Slot
↓
Atomic Booking Transaction
```

لازم نمنع double booking.

نعمل DB transaction + lock.

إذا شخصين ضغطوا نفس الموعد في نفس millisecond:

واحد فقط ينجح.

---

# 24. Payment

النسخة الحالية:

```text
Booking
→ Payment at clinic
```

Online payment:

**Future Feature.**

لكن الـdatabase تكون مستعدة:

```text
payment_status
payment_method
transaction_reference nullable
```

---

# 25. Walk-in

Secretary:

```text
Find/Create Patient
↓
Check availability
↓
Create Walk-in Appointment
↓
Check-in
↓
Queue
```

لو Patient ليس عنده App:

يعمل له Patient Profile باستخدام National ID.

---

# 26. Queue System

الـQueue تعتمد على:

```text
Appointment
+
Check-in
+
Actual Consultation State
```

Patient يشوف:

```text
أمامك 3 مرضى
```

مش:

```text
انتظر 26 دقيقة
```

لكن الـbackend نفسه يحسب الوقت داخليًا.

---

# 27. Doctor Delay Logic

بداية اليوم:

```text
Scheduled first patient = 10:00
Actual first consultation = 10:25

delay_offset = +25 minutes
```

نعمل shift للـprojected schedule.

وبعد كل Consultation:

```text
planned duration
vs
actual duration
```

نحدث projection.

لكن واجهة المريض الأساسية تفضل:

```text
عدد المرضى أمامه
+
اقترب دورك
```

ولما يحصل delay ملحوظ:

Push notification.

---

# 28. Queue Realtime

الحاجات دي لازم Online:

```text
Checked-in
Queue order
Start consultation
End consultation
Current patient
Number ahead
```

باستخدام:

**Laravel Reverb + Redis + WebSocket.**

Reverb يدعم horizontal scaling باستخدام Redis Pub/Sub ووضع عدة Reverb servers خلف Load Balancer. ([Laravel][5])

---

# 29. Consultation Local Resilience

Doctor Desktop يخزن Draft محليًا:

```text
Encrypted SQLite
```

كل تعديل:

```text
autosave local
+
background sync
```

الدكتور ما يستناش network round-trip مع كل حرف.

لكن Queue نفسها online.

الفكرة:

```text
Clinical draft
→ local-first resilient

Queue state
→ server-authoritative realtime
```

---

# 30. Prescription Model

الروشتة:

```text
Prescription
    ├── Medication 1
    ├── Medication 2
    └── Medication N
```

كل Medication:

```text
medication_id
dose
frequency
duration
start_date
end_date
route optional
doctor_free_note
reminder_configuration
```

---

# 31. Prescription Free Notes

Structured لا تعني إننا نقيد الدكتور.

مثال:

```text
Medication:
X

Dose:
20mg

Frequency:
Twice daily

Duration:
14 days

Doctor Note:
بعد الأسبوع الأول قلل الجرعة...
```

`Doctor Note` نص حر.

---

# 32. Medication Reminder

السيستم **لا يحول تلقائيًا**:

```text
3 times/day
```

إلى:

```text
08:00
16:00
00:00
```

بدون تأكيد.

الدكتور يحدد أحد الآتي:

```text
Exact Times
OR
Interval
OR
Generated times confirmed by doctor
```

مثلاً:

```text
Every 8 hours
```

أو:

```text
08:00
16:00
00:00
```

التطبيق يعمل Notification فقط.

لا يوجد:

```text
Taken
Skip
Adherence
```

---

# 33. Prescription Active Period

كل Medication لها end date.

Prescription:

```text
active_until =
MAX(medication.end_date)
```

مثال:

```text
Medicine A → 1 month
Medicine B → 2 months

Prescription active → 2 months
```

---

# 34. Prescription Immutability

أقترح State Machine واضحة:

```text
DRAFT
↓
FINALIZED
↓
EXPOSED
↓
AMENDED
```

`DRAFT`:

الدكتور يعدل بحرية.

`FINALIZED`:

ظهرت للمريض.

لكن قبل الاستخدام الخارجي يمكن correction مسجلة.

`EXPOSED` يحدث عند:

```text
Find My Medicines
OR
Prescription printed/exported
```

دي إضافة أمان مهمة؛ لأن الروشتة المطبوعة خرجت هي الأخرى من النظام.

بعد EXPOSED:

**ممنوع تعديل أو حذف النسخة الأصلية.**

---

# 35. Prescription Error

لو اكتشف الدكتور خطأ بعد Lock:

```text
Create Amendment
↓
Original stays immutable
↓
New corrected version
↓
Reason mandatory
↓
Doctor identity stored
↓
Timestamp
↓
Patient high-priority notification
```

المريض يشوف:

```text
This prescription has been corrected.
Open latest version.
```

مش Delete.

---

# 36. Audit Proof

نسجل:

```text
doctor_id
prescription_id
original_version
new_version
changed_fields
reason
timestamp
IP
device_id
request_id
```

وبالتالي نعرف قانونيًا مين كتب إيه ومتى.

---

# 37. Printing

Doctor Desktop يدعم:

```text
Prescription Print
Medical Report
Sick Leave
Referral
```

Prescription:

```text
Doctor Name
Specialty
Clinic
Patient
Date
Medication
Dose
Frequency
Duration
Instructions
Signature/stamp area
```

لا QR Code حاليًا.

---

# 38. Lab Requests

الـLab entity مستقلة عن Prescription حتى لو اتعملوا في نفس Visit.

Doctor:

```text
Master Lab List
+
Free Text
```

مثلاً:

```text
CBC
Liver Function
HbA1c
```

أو:

```text
Custom Test
```

---

# 39. Lab Lifecycle

```text
REQUESTED
↓
Patient:
Upload Result
OR
Mark Delivered
```

لو Upload:

```text
UPLOADED
↓
DOCTOR_REVIEWED
```

لو Delivered:

```text
PATIENT_MARKED_DELIVERED
↓
DOCTOR_CONFIRMED_RECEIVED
↓
REVIEWED
```

المريض لا يستطيع إغلاق الطلب بنفسه.

---

# 40. Medical Files

Supported:

```text
PDF
Images
```

Storage:

**Private S3 bucket.**

DB تخزن metadata فقط.

```text
file_id
owner_patient_id
object_key
mime
size
hash
uploaded_by
uploaded_at
```

---

# 41. File Upload Pipeline

```text
Request upload
↓
Generate short-lived signed upload
↓
Upload
↓
MIME validation
↓
Size validation
↓
Malware scan
↓
Hash
↓
Available
```

Buckets ممنوع Public.

Downloads تكون signed URL قصيرة العمر.

كل Doctor Download:

```text
file_access_log
```

---

# 42. AI Reading Lab Reports

AI Doctor يستطيع استخدام:

```text
Lab reports
Textual medical reports
```

لو PDF فيه text:

نستخرجه مباشرة.

لو scanned:

```text
OCR fallback
```

لكن **لا نقوم بتشخيص Medical Imaging من pixels حاليًا**.

Radiology images:

```text
Doctor can view
AI doesn't interpret
```

---

# 43. Patient Home

Home فيها:

```text
Hero / Today
Medical AI
Find Medicine
Find Doctor
Appointments
Prescriptions
Medical Record
Labs & Reports
Chat
```

Hero تختار أهم حدث:

```text
Upcoming dose
Upcoming appointment
Pending lab
New prescription
Follow-up
```

---

# 44. Find Doctor Manual

Search:

```text
Doctor name
Specialty
```

Display:

```text
Rating
Locations
Types
Prices
Availability
Distance
```

---

# 45. AI Doctor Recommendation Ranking

ترتيب:

```text
1. Earliest availability
2. Rating descending
```

Distance:

```text
display only
```

لا تدخل Ranking.

---

# 46. Reviews

Review مسموحة فقط إذا:

```text
appointment.status = COMPLETED
AND
appointment.patient_id = current_patient
```

واحد Review لكل completed appointment.

Cancelled/No-show:

ممنوع Review.

---

# 47. Doctor ↔ Patient Chat

يتفتح بعد:

```text
Completed Consultation
```

مدة:

```text
48 hours
```

بعدها:

```text
Read-only
```

ولا نمسح History.

MVP:

```text
Text chat
```

الـEmergency Specialists Chat:

```text
Coming Soon
```

يتعرض في UI كـfuture feature لكن غير Functional.

---

# 48. Pharmacy Organization Model

```text
Pharmacy Organization
       │
       ├── Branch 1
       │
       ├── Branch 2
       │
       └── Branch N
```

كل Branch:

```text
Location
Inventory
POS
Invoices
Purchases
Payment methods
Alerts
Settings
```

---

# 49. Master Medication Database

Admin فقط يعدلها.

Medication ممكن يحتوي:

```text
Brand Name
Generic Name
Active Ingredient
Strength
Dosage Form
Manufacturer
Barcode
Package information
Search aliases
```

نفس drug variation = SKU منفصل.

مثلاً:

```text
Panadol 500 Tablets
Panadol Extra
Panadol Syrup
```

كل واحدة Medication مستقلة.

---

# 50. Medication Packaging

لازم من البداية ندعم:

```text
Box
Strip
Tablet
Bottle
Piece
```

لو:

```text
1 box = 2 strips
1 strip = 10 tablets
```

نحدد smallest tracked unit.

ده يمنع مشاكل:

```text
2.5 boxes
```

ونحسب stock بدقة.

---

# 51. Pharmacy Batch Model

كل Batch:

```text
medication_id
branch_id
batch_number
expiry_date
quantity
cost
```

مثال:

```text
Panadol:
Batch A = 20, Jan
Batch B = 40, Jun
```

UI تعرض:

```text
Total = 60
```

لكن النظام داخليًا يحتفظ بالـ20 والـ40 منفصلين.

---

# 52. FEFO

بيع الدواء:

```text
Find non-expired batches
↓
ORDER BY expiry_date ASC
↓
Consume nearest expiry first
```

**First Expire First Out.**

Expired batch:

ممنوع البيع منه.

---

# 53. Stock Ledger

ما نعملش:

```text
quantity = quantity - 5
```

وخلاص.

نعمل Immutable Stock Movements:

```text
PURCHASE_RECEIVE +50
SALE -5
RETURN +2
EXPIRY -10
ADJUSTMENT -1
INVOICE_CANCEL +5
```

Current stock:

ناتج ledger/batches مع cached totals.

وده مهم جدًا للـaudit.

---

# 54. Low Stock

كل Medication/Branch:

```text
reorder_threshold
```

مثلاً:

```text
10 units
```

إذا:

```text
available_quantity <= threshold
```

Alert.

لما stock يرجع أعلى:

Alert reset.

---

# 55. Expiry Alert

Branch يحدد:

```text
30 days
60 days
90 days
```

Daily job:

```text
expiry_date - today <= configured_threshold
```

ثم Alert.

---

# 56. Purchase Orders

عندنا Supplier Records داخل النظام.

لكن لا يوجد Supplier API Integration.

Flow:

```text
Create Purchase Order
↓
Requested quantities
↓
Shipment arrives
↓
Receive
```

قبل Receive:

الصيدلي يقدر يعدل:

```text
received_quantity
```

لو لم يعدل:

```text
received_quantity = ordered_quantity
```

عند Receive:

```text
create batches
create stock movements
update inventory
close/partial PO
```

---

# 57. Partial Receive

لو طلب:

```text
100
```

وصل:

```text
80
```

نسجل:

```text
ordered = 100
received = 80
remaining = 20
status = PARTIALLY_RECEIVED
```

---

# 58. POS

POS يدعم:

```text
Search/Barcode
Cart
Quantity
Discount if configured
Cash
Card
Invoice
Return
Refund
Cancel
```

Branch تحدد:

```text
Cash
Card
Both
```

وده اللي يظهر للمريض عند البحث.

---

# 59. Invoice Cancellation

Invoice **لا تُحذف**.

```text
PAID
↓
CANCELLED
```

وعمل Reverse Stock Movements.

---

# 60. Return/Refund

Return Entity مستقلة:

```text
invoice_id
item_id
qty
reason
refund_amount
actor
date
```

إذا الـmedicine يمكن إرجاعه للمخزون تشغيليًا:

```text
RESTOCKABLE
```

وإلا:

```text
NON_RESTOCKABLE
```

والقرار النهائي يتوافق مع السياسة القانونية للصيدلية.

---

# 61. Pharmacy Owner

Owner يشوف:

```text
all branches
stock
sales
invoices
alerts
performance
```

لكن:

```text
Branch-to-branch stock transfer
```

Future.

---

# 62. Existing Pharmacy Systems

Branch تختار:

```text
NATIVE
or
INTEGRATED
```

### Native

سيستمنا هو:

```text
Source of Truth
```

### Integrated

برنامج الصيدلية القديم هو:

```text
Source of Truth
```

وسيستمنا يبقى Mirror.

---

# 63. Connector Architecture

```text
External Pharmacy DB/API
          ↓
       Adapter
          ↓
 Canonical Medication Format
          ↓
 Integration API
          ↓
 PostgreSQL Mirror
          ↓
 Patient Search
```

كل System مختلف له Adapter.

ممنوع نفترض SQL schema واحدة.

---

# 64. Connector Product Mapping

External system قد يقول:

```text
PANA-500-20
```

بينما عندنا:

```text
Medication UUID 123
```

نعمل Mapping:

```text
external_product_id
↔
master_medication_id
```

Unmatched products تدخل Queue للمراجعة.

---

# 65. Sync Safety

كل sync record:

```text
connector_id
started_at
finished_at
status
records_read
created
updated
failed
cursor
```

Upserts تكون idempotent.

يعني نفس Sync لو اتنفذ مرتين:

لا يضاعف المخزون.

---

# 66. Stale Pharmacy Data

لو integrated pharmacy لم تعمل sync لمدة طويلة:

ماينفعش نقول للمريض confidently إن الدواء Available.

نحتفظ:

```text
last_synced_at
```

ولو تجاوز configurable freshness threshold:

```text
STALE
```

نستبعده أو نظهر Availability غير مؤكدة.

---

# 67. Patient Find Medicine

Manual:

```text
Search medication
↓
resolve Master Medication
↓
find branches where available
↓
PostGIS distance
↓
nearest first
```

Patient يرى:

```text
Branch
Available
Distance
Payment methods
Directions
```

لا يرى السعر.

PostGIS `ST_DWithin` يدعم distance filtering ويستطيع الاستفادة من spatial indexes. ([PostGIS][6])

---

# 68. Find My Prescription

نفصل الأدوية:

```text
Medicine 1
Medicine 2
Medicine 3
Medicine 4
Medicine 5
```

نسأل inventory مرة واحدة بكفاءة.

Aggregation:

```text
Branch A → 5/5
Branch B → 4/5
Branch C → 2/5
```

Ranking:

```text
coverage count DESC
distance ASC
```

وفي نفس الوقت المستخدم يقدر يفتح كل Medication ويشوف الفروع الخاصة بها.

---

# 69. Old Prescriptions

المريض يرى:

```text
Current
Previous
```

لكن:

`Find Medicines`

متاح فقط للـactive/current prescription.

---

# 70. Medication Search Database

مش هنستخدم Elasticsearch من أول يوم.

نستخدم PostgreSQL:

```text
GIN
pg_trgm
normalized search fields
aliases
```

وده أكثر من كافي للـMedication Catalog الأولي.

OpenSearch مستقبلًا لو search scale فعليًا احتاجه.

---

# 71. Qdrant Collections

نعمل:

```text
doctor_knowledge
patient_knowledge
pharmacy_knowledge
clinical_documents
```

مش:

```text
collection_per_doctor
```

ومش:

```text
collection_per_specialty
```

Qdrant نفسه يوصي غالبًا بـshared collections مع payload-based multitenancy بدل Collection لكل tenant بسبب overhead. ([Qdrant][7])

---

# 72. Doctor Knowledge Payload

كل chunk:

```text
scope_type
scope_id
specialty_id
doctor_id
document_id
version
language
status
page
section
chunk_index
```

Shared:

```text
scope_id = specialty:cardiology
```

Private:

```text
scope_id = doctor:<uuid>
```

---

# 73. Doctor AI Retrieval

لو Doctor Cardiology رقم 100:

allowed:

```text
specialty:cardiology
OR
doctor:100
```

لا يمكنه search:

```text
doctor:101
```

الـfilter ينشئه Server.

المستخدم **لا يرسله من Flutter**.

---

# 74. Qdrant Multitenancy

`doctor_id / scope_id / patient_id`

نعمل لهم Payload Index.

وللـtenant field نستخدم:

```text
is_tenant = true
```

Qdrant يستطيع تنظيم vectors الخاصة بنفس tenant معًا لتحسين filtered retrieval. ([Qdrant][8])

---

# 75. Patient Knowledge

Collection منفصلة:

```text
patient_knowledge
```

لا تستخدم raw Doctor KB.

تحتوي:

```text
Patient-safe educational knowledge
Triage knowledge
Symptoms
Specialty routing
Red flags
General medical education
```

---

# 76. Pharmacy Knowledge

```text
pharmacy_knowledge
```

تحتوي:

```text
Active ingredients
Interactions
Contraindications
Uses
Side effects
Storage
Warnings
Dose references
```

لكن stock ليس Vector Data.

---

# 77. Pharmacy AI Tools

سؤال:

> عندي كام علبة Panadol؟

ما يروحش Qdrant.

```text
AI
↓
Tool call
↓
Laravel Inventory API
↓
PostgreSQL
```

سؤال:

> ما أشهر contraindications للمادة X؟

```text
Qdrant RAG
```

---

# 78. Clinical Documents Collection

Lab/report chunks:

```text
patient_id
document_id
document_type
date
verification_status
```

لكن structured Medical Record يفضل PostgreSQL.

---

# 79. Hybrid Retrieval

مش Dense only.

```text
Dense semantic search
+
Sparse lexical search
↓
Fusion
↓
Reranker
↓
LLM
```

Qdrant يدعم hybrid semantic + lexical queries وكذلك multi-stage retrieval. ([Qdrant][9])

وده مهم للأسماء الطبية الدقيقة.

---

# 80. Embeddings

Baseline:

**BGE-M3**

لأنه multilingual ومناسب عربي/English.

لكن قبل Production نعمل benchmark ضد Medical evaluation dataset.

مش هنعتبر اختيار Embedding Model قرار أبدي.

---

# 81. Reranking

Pipeline مبدئي:

```text
Dense top 30
Sparse top 30
↓
Fusion
↓
Top 20
↓
Reranker
↓
Top 5–8
↓
LLM
```

ممنوع نرمي 50 chunk للـLLM عشوائيًا.

---

# 82. Chunking

Structure-aware:

```text
Chapter
Section
Subsection
Paragraph
Table
```

Baseline:

```text
400–800 tokens
10–15% overlap
```

ثم يتضبط بالـevaluation.

---

# 83. Knowledge Base Versioning

```text
Document
    ├── v1 inactive
    ├── v2 inactive
    └── v3 active
```

Retrieval:

```text
status = active
```

القديم لا يحذف.

---

# 84. Doctor Private Knowledge

Doctor يرفع ملف:

```text
Upload
↓
scope = doctor:<doctor_id>
↓
Private
```

لا يحتاج Admin Approval عشان يستخدمه لنفسه.

لكنه **لا يتحول Shared** تلقائيًا.

---

# 85. Admin Shared Knowledge

Admin:

```text
Specialty
↓
Upload
↓
Version
↓
Process
↓
Activate
```

كل أطباء التخصص يستخدموه.

---

# 86. KB Ingestion

```text
Upload
↓
S3
↓
knowledge_document = PROCESSING
↓
Background Job
↓
Parse
↓
Clean
↓
Chunk
↓
Embed
↓
Qdrant
↓
READY
```

Failure:

```text
FAILED
+
error reason
+
retry
```

---

# 87. Doctor AI Context

أثناء Active Consultation:

```text
Current Visit
Full Medical History
Allergies
Current Medications
Labs
Doctor Private KB
Specialty Shared KB
Question
```

يدخلوا AI orchestration.

لكن National ID/phone/name **لا يرسَلوا للـLLM بلا داعٍ**.

---

# 88. Doctor AI Capabilities

يسمح:

```text
Summarize
Differential diagnosis
Suggested diagnosis
Suggested tests
Clinical considerations
Explain lab
Medical questions within specialty
```

لا يسمح:

```text
Auto prescription
Questions unrelated to medicine
Medical domain outside doctor specialty
```

---

# 89. AI Write Permissions

AI:

**Read/Recommend.**

لا يعدل Medical Record بنفسه.

لو AI أعطى نص:

```text
Copy to notes
```

الدكتور هو اللي يعمل action.

ونتسجل:

```text
inserted_by_doctor
source_ai=true
```

لكن المسؤول عن القرار الدكتور.

---

# 90. Patient AI Intake

المرحلة الأولى Fixed:

```text
Main complaint
Duration
Severity
Basic relevant context
Associated symptoms
```

بعدها:

```text
Dynamic questioning
```

حسب الإجابات.

---

# 91. Patient AI Red Flags

ما نعتمدش على LLM وحده.

نعمل:

```text
Deterministic safety rules
+
LLM clinical reasoning
+
confidence/clarification logic
```

إذا clear high-risk combination:

```text
STOP normal wizard
↓
Emergency Recommendation
```

إذا uncertainty:

```text
Ask clarification
```

مش أي صداع = Emergency.

---

# 92. Patient AI Output

يقول:

```text
Possible causes
Urgency
Recommended specialty
```

لا يقول للمريض:

```text
You definitely have disease X
```

---

# 93. AI → Booking

لو النتيجة:

```text
Recommended specialty = Cardiology
```

AI يعمل tool:

```text
find_doctors(specialty)
```

Backend يرجع:

```text
availability
rating
locations
distance
```

ثم:

```text
availability first
rating second
```

Patient يختار.

بعدها AI يمكنه استخدام:

```text
get_slots()
book_slot()
```

والـbooking النهائي يحتاج user confirmation.

---

# 94. AI Isolation

إذا:

```text
FastAPI down
Qdrant down
Embedding worker down
LLM provider down
```

الآتي يستمر:

```text
Patient account
Doctors
Appointments
Queue
Medical Records
Consultations
Prescriptions
Labs
Pharmacy
POS
Inventory
Medicine Search
Chat
Notifications
```

AI ليس dependency للـCore.

---

# 95. LLM Provider Abstraction

ما نكتبش:

```text
OpenAIService everywhere
```

نعمل:

```text
LLMProvider interface
```

مثلاً:

```text
generate()
stream()
toolCall()
health()
```

ثم adapters.

يسمح نغير provider بدون تغيير النظام.

---

# 96. AI Traceability

حتى لو الدكتور لا يرى Citations:

نخزن داخليًا:

```text
retrieved_document_ids
chunk_ids
model
prompt_version
response_id
latency
token usage
```

عشان لو AI قال حاجة غلط نعرف جابها منين.

---

# 97. Prompt Injection Protection

Uploaded KB تعتبر **data** وليس instructions.

ممنوع document يقول:

> Ignore previous rules.

ويغير AI policy.

السيستم prompt + policy layer أعلى من retrieved content.

---

# 98. AI Conversation Storage

Doctor / Pharmacy AI chats:

PostgreSQL:

```text
ai_conversations
ai_messages
```

مش Qdrant إلا لو احتجنا semantic chat memory مستقبلًا.

Patient symptom conversation تتخزن كconversation مستقلة، ولا تتحول Medical Record تلقائيًا.

---

# 99. Realtime Architecture

Channels مثل:

```text
patient.{id}
doctor.{id}
clinic.{location_id}
appointment.{id}
chat.{thread_id}
pharmacy.{branch_id}
```

كلها Private Channels.

Authorization من Laravel.

---

# 100. Notifications

FCM.

Types:

```text
Appointment reminder
Appointment changed
Doctor delay
Queue approaching
Prescription created
Prescription corrected
Lab requested
Lab uploaded/reviewed
Follow-up
Medication reminder
Chat message
```

SMS فقط:

```text
Registration OTP
```

---

# 101. Notification Delivery

DB:

```text
notifications
notification_deliveries
```

كل Notification:

```text
created
queued
sent
delivered if available
failed
```

Failure retry via queue.

---

# 102. Background Queues

Queues منفصلة:

```text
critical
notifications
files
ai
kb_ingestion
integrations
analytics
reports
backups
```

مثلاً:

`Prescription corrected notification`

→ critical.

`Monthly analytics`

→ analytics.

---

# 103. Laravel Horizon

Horizon نستخدمه لمراقبة:

```text
throughput
runtime
failed jobs
worker allocation
```

Laravel Horizon مصمم لإدارة ومراقبة Redis-backed queues. ([Laravel][10])

---

# 104. Transactional Outbox

دي مهمة جدًا.

مثلاً:

```text
Appointment Completed
```

داخل نفس DB transaction:

```text
update appointment
create encounter state
insert outbox_event
COMMIT
```

Worker بعد كده:

```text
outbox
→ Reverb
→ FCM
→ analytics
```

وبالتالي مايحصلش:

```text
DB saved
but notification/event lost
```

---

# 105. API Design

REST:

```text
/api/v1/...
```

JSON.

OpenAPI specification تبقى Source of Truth للـcontracts.

نولد Flutter Type-safe API clients منها.

---

# 106. API Rules

كل response لها:

```text
data
meta
errors
request_id
```

Pagination:

```text
cursor pagination
```

للـlarge collections.

Dates:

```text
ISO-8601
UTC storage
```

Display:

```text
Africa/Cairo
```

مهم جدًا نستخدم timezone identifier، مش `UTC+2` ثابت؛ لأن DST يتغير.

---

# 107. Idempotency

مهم في:

```text
Booking
End consultation
Prescription finalize
POS sale
Refund
Purchase receive
External sync
```

Client يرسل:

```text
Idempotency-Key
```

لو request اتكرر بسبب network retry:

نرجع نفس النتيجة.

ما نعملش فاتورتين.

---

# 108. Database

PostgreSQL هي Source of Truth.

PostgreSQL الحديث يدعم GIN indexes للـJSONB عند الحاجة، لكن البيانات الأساسية اللي نبحث/نربط عليها كثيرًا تفضل relational columns وليس JSON dump. ([PostgreSQL][11])

---

# 109. Important Database Tables

تقريبًا:

```text
users
user_devices
otp_requests
patient_profiles

doctor_profiles
doctor_verification_documents
specialties
clinic_locations
clinic_staff

doctor_schedules
schedule_exceptions
appointment_types
appointments
appointment_status_events
queue_entries

encounters
encounter_history
diagnoses
clinical_notes
allergies
chronic_conditions
current_medications

prescriptions
prescription_versions
prescription_items
prescription_access_events
prescription_amendments

lab_catalog
lab_requests
lab_results
medical_files
file_access_logs
medical_reports
referrals

pharmacy_organizations
pharmacy_branches
branch_payment_methods

medications
active_ingredients
medication_aliases
medication_packaging

stock_batches
stock_movements
stock_balances
stock_alerts

suppliers
purchase_orders
purchase_order_items
goods_receipts

invoices
invoice_items
payments
returns
refunds

integration_connectors
integration_product_mappings
integration_sync_runs

chat_threads
chat_messages

notifications
notification_deliveries

knowledge_documents
knowledge_versions
knowledge_ingestions

ai_conversations
ai_messages
ai_usage_logs

audit_events
outbox_events

daily_analytics
backup_runs
```

---

# 110. IDs

نستخدم UUID/UUIDv7 وليس sequential IDs exposed publicly.

يساعد:

```text
distributed creation
less enumeration risk
```

Internal indexes تتظبط بناءً عليه.

---

# 111. Database Indexing

مثلاً:

```text
appointments:
(doctor_id, appointment_date, status)
(patient_id, created_at)

stock:
(branch_id, medication_id)
(branch_id, expiry_date)

medical record:
(patient_id, created_at)

doctor availability:
(doctor_id, location_id, day)

audit:
(actor_id, created_at)
(entity_type, entity_id, created_at)
```

PostGIS GiST index على locations.

---

# 112. Partitioning

مش كل Table.

نستخدم monthly partitioning فقط للحاجات الضخمة:

```text
audit_events
notification_deliveries
ai_usage_logs
stock_movements
possibly appointment_events
```

بعد ما الحجم يبررها.

---

# 113. Redis

Redis استخدامه:

```text
Cache
Rate Limits
Distributed Locks
Queue backend
Realtime Pub/Sub
Short-lived data
```

لكن ما نخليش Redis Source of Truth لأي Medical Record.

---

# 114. Redis Separation

Production الكبير:

```text
Redis A → cache/realtime
Redis B → queues
```

عشان Queue pressure ما يقتل الـrealtime.

---

# 115. Cache Strategy

نCache:

```text
Medication Catalog
Specialties
Doctor directory summaries
Appointment availability short TTL
System settings
Public pharmacy summaries
```

نتجنب caching PHI إلا لو هناك سبب واضح ومتحكم فيه.

---

# 116. Authentication

Admin Web:

```text
Sanctum Cookie Session
HttpOnly
Secure
SameSite
CSRF
```

Flutter Apps:

```text
Bearer device token
```

Token stored في secure storage.

Device sessions تظهر للمستخدم ويمكن revoke.

---

# 117. Security

Passwords:

```text
Argon2id
```

لا passwords plain.

TLS 1.2+ everywhere.

Database غير exposed public.

Qdrant غير exposed للإنترنت.

Redis غير exposed للإنترنت.

S3 buckets private.

---

# 118. MFA

أرشح mandatory:

```text
Admin
Doctor
Pharmacy Owner
```

على الأقل TOTP.

Patient:

password + phone verification، وMFA optional مستقبلًا.

---

# 119. Rate Limiting

مثلاً configurable:

```text
Login
OTP request
OTP verify
AI requests
Search
File upload
```

OTP send يكون aggressive rate-limit لمنع SMS abuse.

---

# 120. Audit Log

Append-only.

يسجل:

```text
Actor
Action
Entity
Date
Device
IP
Request ID
Before/after reference
```

أحداث مهمة:

```text
Medical record view
Medical file download
Prescription creation
Prescription correction
Appointment access
Admin verification
Stock adjustment
Refund
Invoice cancel
KB update
```

---

# 121. Tamper Evidence

للحاجات الحساسة جدًا:

نعمل hash chaining:

```text
event_hash =
HASH(previous_hash + event_payload)
```

وبالتالي التلاعب بسجل قديم يبان.

---

# 122. Logs

Application logs **ممنوع** تحتوي:

```text
National ID
Raw medical history
Prescription text
Lab contents
Passwords
Tokens
```

Logs تستخدم IDs فقط.

---

# 123. Privacy / AI

لو هنستخدم external LLM:

لا نرسل:

```text
Name
Phone
National ID
Address
```

إلا لو لها ضرورة واضحة، وهي غالبًا غير ضرورية.

نرسل:

```text
Patient pseudonymous ID
Age
Gender
Clinical facts needed
```

وموضوع cross-border processing والعقود مع provider لازم يتراجع تحت القانون المصري قبل Production. الـPDPC يوضح أن القانون واللائحة ينظمان collection, processing, storage, use and transfer للبيانات الشخصية. ([مركز حماية البيانات الشخصية][1])

---

# 124. Qdrant Security

Flutter لا يعرف أصلًا عنوان Qdrant.

```text
Flutter
↓
Laravel authorization
↓
AI Service
↓
Qdrant
```

Qdrant internal network فقط.

---

# 125. Qdrant Scaling

Development:

```text
1 node
```

Serious production:

```text
3 nodes
Replication Factor >= 2
```

Qdrant يوصي بالبدء vertical scaling ثم horizontal عند الحاجة، ويؤكد على replication للـresilience؛ documentation توصي RF≥2 في production. ([Qdrant][12])

نبدأ عدد shards متناسب مع cluster بدل over-sharding عشوائي، ثم reshard حسب الحجم.

---

# 126. S3 Backup

Medical files:

```text
Versioning ON
Encryption ON
Lifecycle rules
No public access
```

والـbackup منفصل عن live bucket.

---

# 127. Database Backup

نعمل:

```text
Continuous WAL/PITR
+
Daily backup
+
Weekly backup
+
Monthly retained copies
```

على أكثر من storage location.

Target أولي:

```text
RPO <= 5 minutes
RTO <= 60 minutes
```

لـCore Medical Data.

القيمة النهائية تتحدد حسب budget/business requirement.

---

# 128. 3-2-1 Backup Principle

على الأقل:

```text
3 copies
2 storage types/locations
1 offsite logically isolated
```

Backup نفسه encrypted.

---

# 129. Restore Tests

Backup بدون Restore Test مالوش قيمة.

نعمل:

```text
automated integrity check
+
scheduled restore drill
```

ونقيس actual RTO.

---

# 130. Qdrant Backup

Qdrant snapshots منفصلة.

لكن لو فقدنا Qdrant بالكامل:

الـMedical Record نفسها **لم تضِع**؛ لأنها PostgreSQL/S3.

نقدر نعيد بناء embeddings من original KB.

---

# 131. AI Failure Recovery

لو Qdrant data corrupt:

```text
S3 original documents
+
PostgreSQL metadata
↓
Re-ingestion
↓
Qdrant rebuild
```

وده سبب إضافي إن Qdrant ما يبقاش Source of Truth.

---

# 132. Performance Targets

نعتبر دي Acceptance SLOs:

| Operation                      |       p95 Target |
| ------------------------------ | ---------------: |
| Normal API Read                |         ≤ 250 ms |
| Normal API Write               |         ≤ 400 ms |
| Doctor/Patient profile         |         ≤ 250 ms |
| Appointment availability       |         ≤ 300 ms |
| Medicine text search           |         ≤ 300 ms |
| Medicine + pharmacy geo search |         ≤ 500 ms |
| Prescription read              |         ≤ 300 ms |
| Queue realtime event           |          ≤ 1 sec |
| Start consultation             |         ≤ 300 ms |
| POS sale                       |         ≤ 400 ms |
| RAG retrieval                  |         ≤ 700 ms |
| AI first token                 | target ≤ 2–3 sec |

AI timing يعتمد أيضًا على LLM provider.

---

# 133. Capacity Target

أول Production Acceptance Target:

```text
Registered users:
250,000+

Concurrent connected:
10,000

Active concurrent:
≈2,000

Sustained API:
500 RPS

Burst:
1,000–1,500 RPS

WebSockets:
20,000 connections headroom

Concurrent AI generations:
50–100 initially
```

دي **targets نختبرها**، مش ادعاء إن مجرد استخدام Laravel يضمنها.

---

# 134. Future Scale Target

بدون rewrite معماري:

```text
1M+ registered users
50k connected clients
thousands RPS
```

عن طريق horizontal scaling.

---

# 135. Laravel Scaling

```text
Load Balancer
     │
 ┌───┼───┐
 ▼   ▼   ▼
API1 API2 API3 ... APIN
```

Laravel nodes stateless.

Sessions shared/appropriate storage.

Files S3.

Redis shared.

DB external.

وبالتالي Add App Node = capacity أعلى.

---

# 136. Reverb Scaling

```text
LB
├── Reverb 1
├── Reverb 2
└── Reverb N
       │
      Redis
```

وده pattern رسمي مدعوم في Reverb. ([Laravel][5])

---

# 137. PostgreSQL Scaling

الترتيب:

```text
Good indexes
↓
Query optimization
↓
PgBouncer
↓
Caching
↓
Primary + Read Replicas
↓
Partition high-volume tables
↓
Only then consider sharding
```

ما نبدأش Sharding من يوم واحد.

---

# 138. Database Connections

استخدام:

**PgBouncer**

خصوصًا مع تعدد Laravel workers.

هدفه يمنع آلاف client connections من التحول إلى آلاف backend PostgreSQL processes/connections.

---

# 139. Initial Production Topology

لـtarget الكبير، نقطة بداية benchmark وليست guarantee:

```text
Load Balancer
Managed / redundant

Laravel API
3 × 8 vCPU / 16 GB RAM

Reverb
2 × 4 vCPU / 8 GB

Queue Workers
2–3 × 4–8 vCPU / 8–16 GB

PostgreSQL Primary
16 vCPU / 64 GB / fast NVMe

PostgreSQL Standby
same class

Read Replica
8–16 vCPU / 32 GB

Redis Cache/Realtime
HA pair

Redis Queue
separate HA pair

Qdrant
3 × 8 vCPU / 32 GB / NVMe
RF=2

AI API
2 × 4 vCPU / 8 GB

Embedding/Reranker
GPU workers according to measured load

S3
Managed object storage
```

لو المستخدمين في البداية 1,000 فقط نقدر نبدأ أصغر جدًا.

المهم architecture تسمح بالتوسع، مش نحرق فلوس من أول يوم.

---

# 140. AI GPU Strategy

لو Embeddings/Reranker self-hosted:

GPU workers منفصلة.

Laravel لا يحتاج GPU.

FastAPI لا يحتاج GPU لكل request.

نقسم:

```text
AI API
↓
Inference Worker Pool
```

LLM الرئيسي ممكن يكون external provider أولًا.

---

# 141. Core Availability

Target:

```text
99.9% monthly
```

AI availability تقاس منفصلة.

AI outage لا تعتبر Core System outage.

---

# 142. Health Checks

لكل service:

```text
/live
/ready
```

فرق مهم:

`live`

process عايش؟

`ready`

هل قادر يستقبل traffic فعلًا؟

---

# 143. Monitoring

Grafana Dashboard تعرض:

```text
API RPS
p50/p95/p99 latency
5xx rate
DB connections
Slow queries
Redis memory
Queue depth
Failed jobs
Reverb connections
Qdrant latency
AI latency
LLM errors
S3 errors
Push failure
SMS failure
```

---

# 144. Admin System Health

الـAdmin يشوف simplified version:

```text
API Healthy
Database Healthy
Realtime Healthy
AI Healthy/Degraded
Queue Healthy
Storage Healthy
Last Backup
Active Users
AI Storage Usage
```

مش infrastructure secrets.

---

# 145. Admin Analytics

```text
Doctors count
Pharmacies
Branches
Patients
Appointments
Completed
Cancelled
No Show
Unclosed
Most requested specialties
Most searched medicines
Active users
AI usage
AI storage
System health
```

لا Pharmacy Stock Availability Dashboard حاليًا.

ولا Medical Record content.

---

# 146. No-show / Unclosed

Appointment statuses:

```text
BOOKED
CHECKED_IN
WAITING
IN_CONSULTATION
COMPLETED
CANCELLED
NO_SHOW
```

Job يومي يكتشف appointments القديمة اللي ما اتقفلتش ويعرضها:

```text
UNRESOLVED
```

للإدارة التشغيلية.

---

# 147. Medical Report

أقترح ندخله في Core لأنه قليل التعقيد ومفيد.

Doctor أثناء/بعد encounter الخاص به:

```text
Medical Report
Sick Leave
Referral
```

Templates قابلة للتعديل.

لا يستطيع إنشاء report لمريض ليس له authorized encounter.

---

# 148. Localization

كل Apps:

```text
Arabic
English
```

Doctor Default:

```text
English
```

لكن قابل للتغيير.

كل text في translation files.

ممنوع hard-coded strings.

---

# 149. Egypt Only

حاليًا:

```text
Country = Egypt
Currency = EGP
Phone rules = Egypt
National ID = Egypt
Medication catalog = Egypt
SMS = Egypt
```

لكن DB ما تكونش مصممة بحيث إضافة بلد ثانية تحتاج rewrite.

مثلاً:

```text
country_code = EG
currency = EGP
```

حتى لو fixed حاليًا.

---

# 150. Location

Store:

```text
latitude
longitude
geography POINT
```

PostGIS.

Patient location لا نخزنه دائمًا لمجرد البحث.

نطلب location permission ونرسل current point للبحث.

---

# 151. Directions

Button:

```text
Directions
```

يفتح Google Maps route للـbranch/clinic.

مش محتاجين نبني navigation engine بنفسنا.

---

# 152. API Failure Handling

Flutter interceptor:

```text
401
→ auth handling

422
→ form validation

409
→ business conflict

429
→ rate limit

5xx
→ retry only if safe/idempotent
```

مش أي request يفشل نعيده تلقائيًا.

POS create مثلاً يعاد فقط بـIdempotency Key.

---

# 153. Local Outbox

Doctor Desktop:

```text
local_outbox
-------------
id
operation
payload
idempotency_key
created_at
attempts
last_error
```

Worker:

```text
pending
↓
sync
↓
ack
↓
delete/archive
```

Encrypted.

---

# 154. Pharmacy Offline

مش هنبني Full Offline-first Pharmacy ERP حاليًا.

POS/stock central truth يحتاج Online.

نقدر local cache للـcatalog/UI responsiveness.

Full offline sales conflict resolution:

**Future if business requires it.**

---

# 155. Doctor Transient Offline

Doctor يقدر يكتب Clinical Draft أثناء قطع مؤقت.

لكن:

```text
Queue updates
Patient visibility
Final server state
```

تحتاج connection.

UI تظهر بوضوح:

```text
Offline — clinical draft saved locally
```

مش توهمه إن كل شيء وصل للسيرفر.

---

# 156. Testing Pyramid

### Backend

```text
Unit
Domain tests
Feature/API tests
Database integration tests
Policy/authorization tests
Queue/event tests
```

### Flutter

```text
Unit
Widget
Repository
Golden tests
Integration/E2E
```

### React

```text
Unit
Component
API integration
E2E
```

---

# 157. Critical Authorization Tests

نعمل automated tests مثل:

```text
Doctor A cannot access Doctor B private KB

Doctor cannot access patient history before check-in

Doctor access revoked after visit

Secretary cannot access diagnosis

Admin cannot access medical record

Patient cannot edit diagnosis

Patient cannot review uncompleted appointment
```

دي أهم من UI tests.

---

# 158. Prescription Tests

لازم:

```text
Cannot mutate exposed prescription
Amendment keeps original
Correction notifies patient
Original remains retrievable
Concurrent update doesn't overwrite
```

---

# 159. Pharmacy Tests

```text
FEFO works
Expired stock never sold
Cancellation reverses movements
Partial receive works
Repeated receive request is idempotent
Refund doesn't duplicate stock
Connector retries don't duplicate quantity
```

---

# 160. Load Testing

نستخدم:

**k6**

Scenarios منفصلة:

```text
Login
Doctor search
Appointment booking
Medicine search
Prescription search
Queue websocket
POS
Medical Record
```

AI نعمل له scenario مستقل.

---

# 161. Load Acceptance

مش هنقول Production Ready قبل:

```text
500 RPS sustained
10k connected users
20k WebSockets target test
p95 thresholds achieved
error rate acceptable
DB stable
queue backlog controlled
```

ثم Stress Test حتى system degradation.

---

# 162. Stress Test

نطلع تدريجيًا:

```text
500
750
1000
1500
2000 RPS
```

ونحدد:

```text
breaking point
bottleneck
recovery behavior
```

---

# 163. AI Evaluation

مش كفاية "الرد شكله حلو".

نعمل datasets لكل specialty.

Metrics:

```text
Retrieval Recall@K
MRR
Relevant chunk rate
Groundedness
Hallucination rate
Latency
Out-of-scope rejection
```

Patient AI:

```text
Red-flag sensitivity
False emergency rate
Specialty routing accuracy
```

---

# 164. Clinical Validation

كل Patient AI/Doctor AI release يتراجع بواسطة medical experts.

Engineer وحده ما يقرر clinical thresholds.

---

# 165. Development Environments

```text
Local
Development
Staging
Production
```

كل بيئة منفصلة:

```text
Database
Redis
Qdrant
S3 bucket
API credentials
SMS settings
AI keys
```

ممنوع Staging تستخدم Production Medical Data الخام.

---

# 166. Docker

كل service Dockerized:

```text
laravel-api
queue-worker
scheduler
reverb
ai-api
ai-worker
qdrant
redis
postgres development only
monitoring
```

Production DB يفضل managed أو HA deployment منفصل.

---

# 167. CI Pipeline

Pull Request:

```text
Formatting
Static analysis
Unit tests
Integration tests
Security scan
Dependency audit
Build
```

بعد merge:

```text
Build immutable Docker image
Push registry
Deploy staging
Migrations
Smoke tests
```

Production:

```text
Approval
Rolling deployment
Health checks
Smoke test
Monitor
```

---

# 168. Database Migrations

Rules:

```text
Backward-compatible first
Deploy code
Backfill
Switch reads/writes
Remove old column later
```

ممنوع Migration تعمل lock على huge table في Production بدون خطة.

---

# 169. Secrets

Secrets:

```text
DB passwords
SMS keys
Firebase
S3
LLM
Qdrant credentials
```

تكون Secret Manager/Vault.

ممنوع Git.

---

# 170. Feature Flags

نحط من البداية:

```text
online_payments
emergency_chat
drug_alternatives
branch_transfers
patient_adherence
medical_imaging_ai
supplier_api_integration
multi_country
```

وبالتالي Coming Soon features لا تحتاج hacks.

---

# 171. Explicit Future Features

مش داخل V1:

```text
Online payment
Emergency doctor chat
Medication alternatives
Medicine reservation
Branch-to-branch transfer
Automated supplier API
Medication Taken/Skipped tracking
Medical image diagnosis
Multi-country
Complex admin roles
```

---

# 172. Data Ownership Rule

دي قاعدة النظام كلها:

```text
PostgreSQL
= Operational/Medical Source of Truth

S3
= Original File Source of Truth

Qdrant
= Search/Retrieval Index

Redis
= Temporary performance/state infrastructure

Analytics
= Derived Data
```

لو Qdrant وقع:

نعيد بناءه.

لو Redis وقع:

نعيد cache.

لو Analytics ضاعت:

نعيد aggregate.

لكن PostgreSQL/S3 لازم نحميهم بأقصى درجة.

---

# 173. Consistency Model

Strong consistency:

```text
Appointments
Patient access
Medical Record
Prescriptions
Invoices
Stock movements
Refunds
Payments
```

Eventual consistency:

```text
Notifications
Analytics
AI indexing
Search mirrors
External pharmacy mirrors
```

---

# 174. أهم Architectural Rule

ممنوع User Request يعمل:

```text
API
→ PDF processing
→ embedding
→ Qdrant
→ Push
→ Analytics
→ External API
→ return
```

ده كارثة.

نعمل:

```text
Request
↓
Authenticate
↓
Validate
↓
Critical transaction
↓
Outbox/jobs
↓
Return immediately
```

الباقي Background.

---

# 175. الترتيب الصحيح للتنفيذ

التنفيذ نفسه أمشيه بالترتيب ده:

```text
Foundation
↓
Auth & Identity
↓
Admin verification
↓
Patients / Doctors / Locations
↓
Schedules / Appointments
↓
Realtime Queue
↓
Medical Records
↓
Consultations
↓
Prescriptions
↓
Labs / Files
↓
Patient Home / Notifications
↓
Chat
↓
Medication Master
↓
Pharmacy Inventory
↓
Batches / FEFO / Alerts
↓
Purchases
↓
POS / Returns / Refund
↓
Medicine Search
↓
External Pharmacy Integration
↓
AI Infrastructure
↓
Doctor RAG
↓
Pharmacy RAG
↓
Patient AI
↓
AI Booking Tools
↓
Analytics
↓
Security Hardening
↓
Load Testing
↓
Disaster Recovery Test
↓
Production
```

السبب إننا **ما نبنيش AI قبل ما الـCore System اللي الـAI هيستخدمه كTools وcontext يكون مستقر**.

---

# 176. Definition of Done للنظام

أنا ما أعتبرش المشروع Production Ready لمجرد إن الشاشات شغالة.

لازم قبل الإطلاق:

```text
Functional requirements pass
Authorization tests pass
Prescription audit tested
Medical record access tested
Backup restore tested
Load targets tested
No critical security findings
AI safety evaluation passed
KB isolation tested
Qdrant failure doesn't break core
Redis failure recovery tested
Queue retry tested
Pharmacy stock ledger reconciles
Monitoring/alerts operating
Staging production-like
Legal/privacy review completed
```

---

# القرار المعماري النهائي

لو هبني المشروع ده من الصفر بناءً على كل اللي اتفقنا عليه، فده الـstack اللي أعتمده:

**Flutter** للـPatient + Doctor + Pharmacy، **React/TypeScript** للـAdmin، **Laravel Modular Monolith + Octane/FrankenPHP** للـCore، **PostgreSQL + PostGIS** كمصدر الحقيقة، **Redis + Horizon + Reverb** للـcache/queues/realtime، **S3** للملفات، و**Python/FastAPI + Qdrant + Hybrid RAG** كـAI subsystem منفصل.

والـtarget الهندسي الأول يبقى **250k+ registered users، 10k simultaneous connected users، 500 sustained RPS، 20k WebSocket headroom، p95 ≤250ms لمعظم reads، و99.9% availability للـCore**، مع اختبار فعلي يثبت الأرقام قبل Production.

والأهم: النظام **لا يعتمد على الـAI كي يعمل**، لا يعتمد على Qdrant لحفظ Medical Truth، لا يسمح للـAdmin أو السكرتير بمشاهدة التاريخ الطبي، لا يسمح بتعديل روشتة مستخدمة بدون Amendment، ولا يسمح لأي Doctor بالوصول إلى Full Medical Record بدون Visit/Attendance فعلي.

دي بالنسبة لي **الـMaster Architecture الأساسية اللي نبني فوقها الـSRS والـDatabase ERD والـAPI Contract والـimplementation tasks**. ([Laravel][13])

[1]: https://pdpc.gov.eg/?utm_source=chatgpt.com "Personal Data Protection Center"
[2]: https://docs.flutter.dev/platform-integration/desktop?utm_source=chatgpt.com "Desktop support for Flutter"
[3]: https://laravel.com/blog/octane-frankenphp?utm_source=chatgpt.com "Octane + FrankenPHP | Laravel - The clean stack for Artisans and agents"
[4]: https://laravel.com/framework/docs/12.x/sanctum?utm_source=chatgpt.com "Laravel Sanctum | Laravel 12.x - The clean stack for Artisans and agents"
[5]: https://laravel.com/framework/docs/11.x/reverb?utm_source=chatgpt.com "Laravel Reverb | Laravel 11.x - The clean stack for Artisans and agents"
[6]: https://postgis.net/stuff/postgis-3.6.1-en.pdf?utm_source=chatgpt.com "PostGIS 3.6.1 Manual"
[7]: https://qdrant.tech/documentation/tutorials/multiple-partitions/?utm_source=chatgpt.com "Multitenancy - Qdrant"
[8]: https://qdrant.tech/documentation/manage-data/multitenancy/?utm_source=chatgpt.com "Multitenancy - Qdrant"
[9]: https://qdrant.tech/documentation/search/hybrid-queries/?utm_source=chatgpt.com "Hybrid Queries - Qdrant"
[10]: https://laravel.com/framework/docs/12.x/horizon?utm_source=chatgpt.com "Laravel Horizon | Laravel 12.x - The clean stack for Artisans and agents"
[11]: https://www.postgresql.org/files/documentation/pdf/18/postgresql-18-US.pdf?utm_source=chatgpt.com "PostgreSQL 18.1 Documentation"
[12]: https://qdrant.tech/documentation/scaling/?utm_source=chatgpt.com "Scaling & Resilience - Qdrant"
[13]: https://laravel.com/docs/13.x/readme?utm_source=chatgpt.com "Laravel Documentation | Laravel 13.x - The clean stack for Artisans and agents"
