# Frontend Integration Guide — IPPIS Ghost Worker Verification API

This document describes the **biometric verification** half of the API: voice (phone) and face (web). All other endpoints (workers, MDAs, payments, alerts, etc.) are catalogued at the bottom and documented in Swagger.

**Base URL (dev):** `http://127.0.0.1:8000/api/v1`
**Interactive docs:** `http://127.0.0.1:8000/api/documentation`

---

## 1. Authentication

The whole API (except 3 public endpoints) is gated by **Laravel Sanctum bearer tokens**.

### Login

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "adamu.bello@ippis.gov.ng",
  "password": "<seeded-password>"
}
```

Response:
```json
{
  "success": true,
  "data": {
    "token": "1|abc123…",
    "user": { "id": 1, "email": "…", "name": "…" }
  }
}
```

Use the token on every authenticated request:
```http
Authorization: Bearer 1|abc123…
Accept: application/json
```

### Other auth endpoints
| Method | Path | Auth |
|---|---|---|
| `POST` | `/auth/login` | Public, rate-limited 10/min |
| `POST` | `/auth/logout` | Sanctum |
| `GET` | `/auth/me` | Sanctum |

### Standard response envelope

Every endpoint (except Laravel validation errors) returns:

```json
{
  "success": true,
  "message": "Optional human-readable message.",
  "data": { ... payload ... }
}
```

On error: `success: false`, `data` may contain an error context object.

Validation errors come back from Laravel directly:
```json
{
  "message": "The image field is required.",
  "errors": { "image": ["The image field is required."] }
}
```
HTTP status: `422`.

---

## 2. Verification Channels — the big idea

Each worker has a `verification_channel` field:

| Value | Meaning |
|---|---|
| `phone` | Worker is verified by Vapi outbound call only |
| `web`   | Worker is verified by face capture only |
| `both`  | Either channel works; admin chooses per attempt |

Set at onboarding step1 (optional, defaults to `phone`). Can be updated later via `PUT /workers/{id}`.

**Routing:**
- `POST /workers/{id}/verify-voice` → 422 if `channel=web`
- `POST /face-verification/start` → 422 if `channel=phone`

---

## 3. VOICE Channel — Vapi outbound calls

### 3.1 Enrol voice (web upload, one-time per worker)

The admin uploads a worker's voice sample from the dashboard. Laravel sends it to the AI service to compute voiceprints, then stores them on the worker row.

```http
POST /api/v1/workers/{worker_id}/enrol-voice
Authorization: Bearer <token>
Content-Type: multipart/form-data

voice_sample: <WAV/MP3/OGG/WebM/M4A file, max 10 MB, ≥1.5s of speech>
```

Success (200):
```json
{
  "success": true,
  "message": "Voice enrolment completed.",
  "data": {
    "worker_id": 1,
    "voice_enrolled": true,
    "voice_template_url": "/storage/biometrics/voices/1_20260514164254.wav",
    "spoof_prob": 0.0001,
    "quality": {
      "duration_sec": 6.3,
      "snr_db": 70.73,
      "too_short": false
    }
  }
}
```

Error cases:
| HTTP | When |
|---|---|
| `422` | Validation fail, audio too short, no speech detected by AI |
| `503` | AI service unreachable |

After success: worker has `voice_enrolled=true` and two embedding columns populated. The audio file is kept under `/storage/biometrics/voices/`.

### 3.2 Trigger a verification call

```http
POST /api/v1/workers/{worker_id}/verify-voice
Authorization: Bearer <token>
```

This kicks off an outbound Vapi call to the worker's phone number. The endpoint returns **immediately (202)** with a `call_id` — the actual verification result arrives later via webhook (Vapi → us → DB row).

Success (202):
```json
{
  "success": true,
  "message": "Verification call dispatched.",
  "data": {
    "call_id": "019e2745-5565-7000-9a1d-536196060aad",
    "status": "dispatched"
  }
}
```

Error cases:
| HTTP | When |
|---|---|
| `422` | Worker missing phone, not enrolled, or `channel=web` |
| `503` | Vapi unreachable or assistant not configured |

### 3.3 What happens during the call

The worker's phone rings (caller ID = Twilio US number). A TTS assistant greets them by name and asks them to say their name + IPPIS ID + today's date for ~10 seconds. The assistant is configured with these per-call variables (already wired — frontend doesn't need to do anything):

| Variable | Source | Example |
|---|---|---|
| `worker_first_name` | `worker.full_name` (first word) | "Adamu" |
| `worker_full_name` | `worker.full_name` | "Adamu Bello" |
| `ippis_id` | `worker.ippis_id` | "IPPIS-001" |
| `mda_name` | `worker.mda.name` | "Ministry of Finance" |
| `today_date` | server date | "Thursday, May 14, 2026" |
| `today_short` | server date | "May 14" |

### 3.4 Polling for the result

After dispatching, poll either:

**(a) the worker** to see new `last_verified_at`:
```http
GET /api/v1/workers/{worker_id}
```

**(b) the verifications list** for the new row:
```http
GET /api/v1/verifications?worker_id={worker_id}&channel=ivr
```

A successful call yields one row with the new verdict + score + recording URL within ~15-60 seconds of dispatch (call duration plus AI processing).

### 3.5 Reading a verification result

```http
GET /api/v1/verifications/{id}
```

Returns:
```json
{
  "success": true,
  "data": {
    "id": 2,
    "worker_id": 1,
    "channel": "ivr",
    "verdict": "PASS",
    "trust_score": 96,
    "speaker_biometric_score": 96,
    "anti_spoof_score": 91,
    "face_liveness_score": null,
    "challenge_response_score": null,
    "replay_detection_score": null,
    "latency_ms": 4254,
    "verified_at": "2026-05-14T16:15:41.000000Z",
    "recording_url": "/storage/verifications/1/a5e0b810-….wav",
    "vapi_call_id": "019e2745-5565-7000-9a1d-536196060aad",
    "call_cost": "0.0110",
    "transcript": "AI: Hello. This is IPIS verification… User: My name is Victor Jayeoba, …"
  }
}
```

**Frontend can use:**
- `recording_url` → `<audio>` playback (concat with the host: `http://127.0.0.1:8000/storage/…`)
- `transcript` → show what the worker said
- `trust_score`, `verdict` → status badge
- `speaker_biometric_score`, `anti_spoof_score` → per-layer breakdown panel

### 3.6 Voice score column meanings

| Column | Range | Meaning |
|---|---|---|
| `trust_score` | 0-100 | Final fused score from AI (this is what determines verdict) |
| `verdict` | enum | `PASS` (≥75 trust) / `FAIL` (<40) / `INCONCLUSIVE` (40-74) |
| `speaker_biometric_score` | 0-100 | Voice match score (ECAPA-TDNN + CAM++ fused similarity × 100) |
| `anti_spoof_score` | 0-100 | (1 − AASIST spoof_prob) × 100. Higher = more confident it's real human |
| `challenge_response_score` | 0-100 or NULL | Not used by current AI pipeline |
| `replay_detection_score` | 0-100 or NULL | Not used by current AI pipeline |
| `face_liveness_score` | 0-100 or NULL | NULL for voice verifications |

---

## 4. FACE Channel — Persona-style web kiosk

3-frame flow: **look straight → turn right → turn left**. Frontend uses webcam (`getUserMedia`), captures one frame at a time, and POSTs each to the backend. Backend validates + runs the AI service on each frame and returns immediately. On any failure the session ends early.

### 4.1 Enrol face (web upload, one-time per worker)

Same `step4` endpoint as before, but now also computes and saves the face embedding behind the scenes:

```http
POST /api/v1/onboarding/{worker_id}/step4
Authorization: Bearer <token>
Content-Type: multipart/form-data

face_image: <JPEG/PNG/WebP file>
```

Success (200):
```json
{
  "success": true,
  "message": "Step 4 completed.",
  "data": {
    "face_template_url": "/storage/biometrics/faces/1_20260514163210.jpg",
    "face_enrolled": true,
    "spoof_prob": 0.04,
    "quality": {
      "face_detected": true,
      "confidence": 0.998,
      "bbox": [120, 80, 280, 320],
      "brightness_ok": true,
      "blur_ok": true
    },
    "onboarding_status": "step4"
  }
}
```

Error cases:
| HTTP | When |
|---|---|
| `422` | No face detected, image too blurry/dark, validation fail |
| `503` | AI service unreachable |

If 422, the file is **not** kept and `face_enrolled` stays `false` — user must re-capture.

### 4.2 Start a verification session

```http
POST /api/v1/face-verification/start
Authorization: Bearer <token>
Content-Type: application/json

{ "worker_id": 1 }
```

Success (201):
```json
{
  "success": true,
  "message": "Face verification session started.",
  "data": {
    "session_id": 7,
    "worker_id": 1,
    "status": "identity_pending",
    "next_step": "frame1",
    "expected_direction": null
  }
}
```

Error cases:
| HTTP | When |
|---|---|
| `422` | Wrong channel (worker is `phone` only), not face-enrolled, validation fail |

Frontend should now show step 1: "Look directly at the camera" and capture frame 1.

### 4.3 Frame 1 (look straight) — identity check

```http
POST /api/v1/face-verification/{session_id}/frame1
Authorization: Bearer <token>
Content-Type: multipart/form-data

image: <JPEG/PNG/WebP, max 5 MB>
```

Success (200) → identity match, proceed:
```json
{
  "success": true,
  "message": "Identity check passed — capture frame 2 (turn head right).",
  "data": {
    "session_id": 7,
    "status": "pose_right_pending",
    "next_step": "frame2",
    "expected_direction": "right",
    "identity": {
      "verdict": "PASS",
      "score": 92
    }
  }
}
```

Failure (422) → identity mismatch, session over:
```json
{
  "success": false,
  "message": "Identity verification failed — face does not match enrolment.",
  "data": {
    "session_id": 7,
    "verification_id": 5,
    "verdict": "FAIL",
    "score": 8
  }
}
```

When you see 422 here: **stop the wizard**. A `verifications` row was already written (FAIL) and a ghost-worker alert was raised. Show the operator the failure reason and let them start a new session if they want.

### 4.4 Frame 2 (turn right) — head-turn check #1

```http
POST /api/v1/face-verification/{session_id}/frame2
Authorization: Bearer <token>
Content-Type: multipart/form-data

image: <captured AFTER prompting the user to turn their head right>
```

Success (200):
```json
{
  "success": true,
  "message": "Right-turn passed — capture frame 3 (turn head left).",
  "data": {
    "session_id": 7,
    "status": "pose_left_pending",
    "next_step": "frame3",
    "expected_direction": "left",
    "pose_right": {
      "passed": true,
      "delta_degrees": 22.5
    }
  }
}
```

Failure (422) → user didn't turn enough or wrong direction:
```json
{
  "success": false,
  "message": "Head-turn challenge failed — please turn your head clearly to the right.",
  "data": {
    "session_id": 7,
    "verification_id": 6,
    "verdict": "FAIL",
    "delta_degrees": 4.1
  }
}
```

Threshold: yaw delta must be ≥ 15° in the expected direction. Same failure semantics as frame1 — session is over.

### 4.5 Frame 3 (turn left) — head-turn check #2 + final verdict

```http
POST /api/v1/face-verification/{session_id}/frame3
Authorization: Bearer <token>
Content-Type: multipart/form-data

image: <captured AFTER prompting user to turn their head left>
```

Success (200) → all 3 checks passed, full PASS:
```json
{
  "success": true,
  "message": "Face verification completed.",
  "data": {
    "session_id": 7,
    "verification_id": 7,
    "verdict": "PASS",
    "score": 92,
    "pose_left": {
      "passed": true,
      "delta_degrees": 19.8
    }
  }
}
```

Failure (422) → left turn failed or any earlier check was inconclusive:
```json
{
  "success": false,
  "message": "Head-turn challenge failed — please turn your head clearly to the left.",
  "data": {
    "session_id": 7,
    "verification_id": 8,
    "verdict": "FAIL",
    "failure_reason": "pose_left_failed"
  }
}
```

On PASS, the backend automatically queues Squad disbursement. On FAIL, a ghost-worker alert is raised.

### 4.6 Inspect a session (for debugging or polling)

```http
GET /api/v1/face-verification/{session_id}
Authorization: Bearer <token>
```

Returns the full session row including all 3 frame URLs, scores, pose deltas, verdict, etc. Useful for showing a recap screen at the end of the flow.

### 4.7 Session lifecycle / TTL

A session is "active" while it's in any of: `identity_pending`, `pose_right_pending`, `pose_left_pending`. Active sessions expire after **10 minutes** since `started_at`. If the operator takes too long, the next frame submission returns **410 Gone** with `"Session expired."` — start over.

If you call `/start` while an old active session exists for the same worker, the old one is automatically marked `expired` and you get a fresh session.

### 4.8 Recommended frontend flow (pseudocode)

```js
// Step 0: ensure worker is enrolled + channel='web' or 'both'
const worker = await GET(`/workers/${id}`);
if (!worker.face_enrolled) abort("Worker hasn't completed face enrolment");
if (worker.verification_channel === 'phone') abort("Worker is phone-only");

// Step 1: start session
const { session_id, next_step, expected_direction } =
  await POST('/face-verification/start', { worker_id: id });

// Step 2: look straight
showPrompt("Look directly at the camera");
const frame1 = await captureFrame();
const r1 = await POST(`/face-verification/${session_id}/frame1`, { image: frame1 });
if (r1.status === 422) return showFail(r1.data);

// Step 3: turn right
showPrompt("Turn your head to the RIGHT");
const frame2 = await captureFrame();
const r2 = await POST(`/face-verification/${session_id}/frame2`, { image: frame2 });
if (r2.status === 422) return showFail(r2.data);

// Step 4: turn left → final verdict
showPrompt("Turn your head to the LEFT");
const frame3 = await captureFrame();
const r3 = await POST(`/face-verification/${session_id}/frame3`, { image: frame3 });

if (r3.status === 200) showPass(r3.data);   // verdict=PASS, score, verification_id
else                   showFail(r3.data);    // verdict=FAIL/INCONCLUSIVE
```

### 4.9 Face score column meanings (`verifications` row)

| Column | Value when face verification |
|---|---|
| `channel` | `"app"` |
| `verdict` | `PASS` only if identity PASS + both pose checks pass |
| `trust_score` | identity score (0-100 from AI) |
| `face_liveness_score` | same as trust_score (fused face number) |
| `speaker_biometric_score` | NULL |
| `anti_spoof_score` | NULL |
| `latency_ms` | sum of all 3 AI roundtrips |

---

## 5. Worker model — key fields the frontend reads

```ts
{
  id: number,
  ippis_id: string,
  full_name: string,
  phone: string | null,
  email: string | null,
  mda_id: number,
  department_id: number | null,
  state_of_posting: string | null,

  // Status flags
  status: 'draft' | 'active' | 'flagged' | 'blocked' | 'suspended',
  onboarding_status: 'draft' | 'step1' | 'step2' | 'step3' | 'step4' | 'step5' | 'step6' | 'completed',

  // Biometric enrolment state
  face_enrolled: boolean,
  face_template_url: string | null,
  voice_enrolled: boolean,
  voice_template_url: string | null,

  // Verification channel preference
  verification_channel: 'phone' | 'web' | 'both',

  // Last verification
  last_verified_at: string | null,  // ISO timestamp
  enrolled_at: string | null,

  // Relations (when loaded)
  mda?: { id, name, ... },
  department?: { id, name, ... },
}
```

**Fields NOT to expose to the frontend** (large/sensitive — the backend never serializes these by default):
- `voice_embedding_ecapa`, `voice_embedding_campplus` (192/512-float arrays)
- `face_embedding` (512-float array)

---

## 6. Onboarding (multi-step)

**Who runs onboarding:** the admin, from the dashboard. The worker is physically present at a kiosk for biometric capture but never logs in. After step 6 + `/complete`, `worker.status` flips to `active`, which is the signal that the worker is eligible for automated/scheduled verifications.

**Channel-aware skipping:** the admin picks `verification_channel` in step 1 (`phone`, `web`, or `both`). Subsequent steps skip biometric enrolment that the worker doesn't need:

| Channel | Step 4 (face) | Step 5 (voice) |
|---|---|---|
| `phone` | Skipped automatically (returns 200, no body required) | Voice clip required |
| `web` | 3-frame live capture required | Skipped automatically (returns 200, no body required) |
| `both` | 3-frame live capture required | Voice clip required |

### Step 1 — Employment details (POST)

```http
POST /api/v1/onboarding/step1
Authorization: Bearer <token>
Content-Type: application/json
```
```json
{
  "full_name": "Adamu Bello",
  "date_of_birth": "1985-03-15",
  "gender": "male",
  "ippis_id": "IPPIS-001",
  "mda_id": 1,
  "department_id": 2,
  "job_title": "Senior Accountant",
  "grade_level": 10,
  "step": 3,
  "employment_date": "2010-06-01",
  "employment_type": "permanent",
  "state_of_posting": "Lagos",
  "lga": "Ikeja",
  "office_address": "1 Treasury Road, Lagos",
  "verification_channel": "both"
}
```
Returns `worker_id` + `onboarding_token`. **`verification_channel` is required** (`phone` / `web` / `both`).

### Step 2 — Personal/identity (PUT)

```http
PUT /api/v1/onboarding/{worker_id}/step2
```
```json
{
  "nin": "12345678901",
  "bvn": "12345678901",
  "phone": "08012345678",
  "email": "worker@gov.ng",
  "home_address": "5 Bode Thomas St, Lagos",
  "next_of_kin_name": "Fatima Bello",
  "next_of_kin_phone": "08098765432",
  "next_of_kin_relationship": "Spouse"
}
```

### Step 3 — Bank/salary (PUT)

```http
PUT /api/v1/onboarding/{worker_id}/step3
```
```json
{
  "salary_amount": 85000,
  "bank_name": "First Bank",
  "bank_code": "011",
  "bank_account_number": "1234567890",
  "bank_account_name": "Adamu Bello"
}
```

### Step 4 — Face enrolment (POST, channel-aware)

**For `phone`-only workers:** no body, no capture. Backend skips and returns 200:
```json
{ "success": true, "message": "Step 4 skipped (phone-only worker).",
  "data": { "face_enrolled": false, "skipped": true, "onboarding_status": "step4" } }
```

**For `web` or `both` workers:** Persona-style live capture. Three frames required:

```http
POST /api/v1/onboarding/{worker_id}/step4
Content-Type: multipart/form-data

frame_straight: <JPEG/PNG/WebP, worker looking at camera, max 5 MB>
frame_right:    <JPEG/PNG/WebP, worker's head turned to their right>
frame_left:     <JPEG/PNG/WebP, worker's head turned to their left>
```

Backend runs in order:
1. `/face/embed` on `frame_straight` → produces 512-d ArcFace embedding (saved on worker)
2. `/face/verify-pose` on `(straight, right, "right")` → must pass ≥15° yaw delta
3. `/face/verify-pose` on `(straight, left, "left")` → must pass ≥15° yaw delta

All 3 must pass for the step to succeed. On any failure, no file is kept and `face_enrolled` stays false. Possible 422 messages:
- `Right-turn liveness failed — please turn your head clearly to the right and retry.`
- `Left-turn liveness failed — please turn your head clearly to the left and retry.`
- `No face detected in image` (AI-side, from `/face/embed`)

Success (200):
```json
{
  "success": true,
  "data": {
    "face_enrolled": true,
    "face_template_url": "/storage/biometrics/faces/1/20260514163210_straight.jpg",
    "spoof_prob": 0.04,
    "quality": { "face_detected": true, "confidence": 0.998, "bbox": [...], "brightness_ok": true, "blur_ok": true },
    "pose_right": { "passed": true, "delta_degrees": 22.5 },
    "pose_left":  { "passed": true, "delta_degrees": 19.8 },
    "onboarding_status": "step4"
  }
}
```

### Step 5 — Voice enrolment (POST, channel-aware)

**For `web`-only workers:** no body, no upload. Skipped, returns 200.

**For `phone` or `both` workers:** voice clip required:

```http
POST /api/v1/onboarding/{worker_id}/step5
Content-Type: multipart/form-data

voice_sample: <WAV/MP3/OGG/WebM/M4A, ≥1.5s of speech, max 10 MB>
```

Backend calls AI `/embed` → saves ECAPA + CAM++ embeddings → sets `voice_enrolled: true`.

Success (200):
```json
{
  "success": true,
  "data": {
    "voice_enrolled": true,
    "voice_template_url": "/storage/biometrics/voices/1_20260514164254.wav",
    "spoof_prob": 0.0001,
    "quality": { "duration_sec": 6.3, "snr_db": 70.73, "too_short": false },
    "onboarding_status": "step5"
  }
}
```

### Step 6 — Squad virtual account (POST)

```http
POST /api/v1/onboarding/{worker_id}/step6
```
No body. Backend creates a virtual bank account for the worker via Squad. Required for salary disbursement on PASS verdicts.

### Complete (POST)

```http
POST /api/v1/onboarding/{worker_id}/complete
```
Final activation. `status` → `active`, `onboarding_status` → `completed`, `enrolled_at` is timestamped, `WorkerEnrolledEvent` fires. **Only after this is the worker eligible for automated/scheduled verification.**

### Status & resume

```http
GET /api/v1/onboarding/{worker_id}/status       (auth)
GET /api/v1/onboarding/resume/{token}            (PUBLIC — no auth, for resume links)
```

### Routes summary

| Method | Path | What |
|---|---|---|
| `POST` | `/onboarding/step1` | Employment details + channel pick → returns `worker_id` + `onboarding_token` |
| `PUT`  | `/onboarding/{worker_id}/step2` | Personal/identity |
| `PUT`  | `/onboarding/{worker_id}/step3` | Bank/salary |
| `POST` | `/onboarding/{worker_id}/step4` | Face: 3-frame live capture OR auto-skip for `phone` |
| `POST` | `/onboarding/{worker_id}/step5` | Voice: clip upload OR auto-skip for `web` |
| `POST` | `/onboarding/{worker_id}/step6` | Create Squad virtual account |
| `POST` | `/onboarding/{worker_id}/complete` | Activate worker (`status: active`) |
| `GET`  | `/onboarding/{worker_id}/status` | Poll progress |
| `GET`  | `/onboarding/resume/{token}` | Resume (public, no auth) |

### Activation is now an explicit admin act

`POST /onboarding/{id}/complete` no longer activates the worker — it moves them to `status='pending_review'`. An admin must then call `POST /workers/{id}/activate` (see section 7) to flip status to `active`. Only `active` workers are eligible for the monthly verification cron.

```
[admin step1] → status=pending_self_enrol
      ↓
[admin or worker fills step2 + 4 + 5]
      ↓
[admin step3 + step6 + complete]   OR   [worker /self-enrol/.../submit]
      ↓
            status=pending_review
                  ↓
        [admin reviews biometrics]
        ↓                       ↓
[admin /activate]      [admin /reject {reason}]
        ↓                       ↓
   status=active          status=rejected
        ↓                       ↓
[cron picks them up]    [admin /issue-qr → status=pending_self_enrol, fresh token]
```

If the admin needs to deactivate a worker later, use `POST /workers/{id}/block` which flips `status='blocked'`. `POST /workers/{id}/unblock` reverses.

---

## 7. Worker Self-Enrolment (QR code, no auth)

The admin pre-creates a worker shell in the dashboard (steps 1, 3, 6 — employment, bank, Squad). They click **"Get QR"** which returns a URL. They print that URL as a QR poster at a government office. Workers walk up, scan the QR with their phone, and complete the **personal info + biometric capture** parts themselves. When they submit, the worker is queued for admin review — they're **not** auto-activated.

### 7.1 Admin issues a QR for a worker

```http
POST /api/v1/workers/{id}/issue-qr
Authorization: Bearer <admin-token>
```

Success (200):
```json
{
  "success": true,
  "message": "Self-enrol QR/URL issued.",
  "data": {
    "worker_id": 42,
    "self_enrol_url": "http://localhost/self-enrol/287b44ef-1581-45b8-8fce-9c784694851d",
    "onboarding_token": "287b44ef-1581-45b8-8fce-9c784694851d",
    "status": "pending_self_enrol"
  }
}
```

The frontend can render the `self_enrol_url` into a QR code (e.g. with `qrcode` npm package) for printing. Any previous token for this worker is voided.

Errors:
| HTTP | When |
|---|---|
| `422` | Worker is already `active`, `blocked`, `flagged`, or `suspended` — can't issue a QR. Use unblock/reset workflows instead. |

### 7.2 Worker scans QR → GET context

```http
GET /api/v1/self-enrol/{token}
```
**No auth needed.** Token is the auth.

Success (200):
```json
{
  "success": true,
  "data": {
    "worker_id": 42,
    "full_name": "Adamu Bello",
    "ippis_id": "IPPIS-001",
    "mda_name": "Ministry of Finance",
    "department_name": "Treasury",
    "job_title": "Senior Accountant",
    "verification_channel": "both",
    "onboarding_status": "step1",
    "pending_steps": ["step2", "step4", "step5"],
    "can_submit": false
  }
}
```

Errors:
| HTTP | When |
|---|---|
| `404` | Token invalid or never issued |
| `410` | Token already used (worker is in `pending_review` / `active` / etc.) |

Frontend uses `pending_steps` to drive the wizard. `can_submit` flips `true` once all required steps for the worker's channel are done.

### 7.3 Worker submits personal info

```http
PUT /api/v1/self-enrol/{token}/step2
Content-Type: application/json
```
Same payload as admin step2 (NIN, BVN, phone, email, home_address, next_of_kin_*).

### 7.4 Worker captures face (3 frames)

```http
POST /api/v1/self-enrol/{token}/step4
Content-Type: multipart/form-data

frame_straight: <JPEG/PNG/WebP>
frame_right:    <JPEG/PNG/WebP>
frame_left:     <JPEG/PNG/WebP>
```

Same behaviour as the admin-side step4 — Persona-style identity + 2 liveness checks. Auto-skipped for `phone`-only workers. Frontend captures from the phone webcam (`getUserMedia` with `facingMode: 'user'`).

### 7.5 Worker uploads voice sample

```http
POST /api/v1/self-enrol/{token}/step5
Content-Type: multipart/form-data

voice_sample: <WAV/MP3/OGG/WebM/M4A, ≥1.5s>
```

Same behaviour as admin step5 — runs AI `/embed`, saves embeddings. Auto-skipped for `web`-only workers. Frontend records from phone mic via `MediaRecorder`.

### 7.6 Worker submits for review

```http
POST /api/v1/self-enrol/{token}/submit
```

Server validates all required steps for the channel are done, then:
- `worker.status = 'pending_review'`
- `worker.onboarding_status = 'completed'`
- Token invalidated (further GET returns 410)

Success (200):
```json
{
  "success": true,
  "message": "Submitted for review. An admin will activate your account within 24 hours.",
  "data": {
    "worker_id": 42,
    "status": "pending_review",
    "next_action": "admin_review"
  }
}
```

Errors:
| HTTP | When |
|---|---|
| `422` | A required step is still pending. Response includes `pending_steps: [...]` |

### 7.7 Self-enrol rate limiting

All `/self-enrol/*` endpoints are rate-limited **30 req/min per IP**. Plenty for a worker filling a 4-step form; tight enough to block scrapers.

---

## 8. Admin Activation Queue

After a worker submits (via self-enrol or admin kiosk), they sit at `pending_review`. The admin reviews their biometrics and decides.

### 8.1 List pending workers

```http
GET /api/v1/workers/pending-activation?mda_id=1&channel=both&search=adamu
Authorization: Bearer <admin-token>
```

Returns paginated workers with `status='pending_review'`, eagerly loading `mda` and `department`. Frontend shows them in a queue.

### 8.2 Approve

```http
POST /api/v1/workers/{id}/activate
Authorization: Bearer <admin-token>
```

Flips `status: pending_review → active`, populates `enrolled_at` (if not already), fires `WorkerEnrolledEvent`. From this moment, the worker is eligible for the monthly verification cron.

Errors:
| HTTP | When |
|---|---|
| `422` | Worker is not in `pending_review` (already active, blocked, etc.) |

### 8.3 Reject

```http
POST /api/v1/workers/{id}/reject
Content-Type: application/json
Authorization: Bearer <admin-token>

{ "reason": "Face photo does not match employment record" }
```

Flips `status: pending_review → rejected`. Reason is recorded in the audit log. Admin can later call `/issue-qr` to give the worker a fresh token if they need to redo enrolment.

### 8.4 Admin review UI hint

On the worker detail page for a pending worker, the admin should see:
- Their submitted personal info
- `face_template_url` → render with `<img src="http://backend/storage/...">`
- `voice_template_url` → render with `<audio controls src="...">`
- Quality scores stored in audit log: face spoof_prob, voice spoof_prob, brightness/blur OK
- Worker's employment record (admin filled this in step 1)

Then **Approve** / **Reject** buttons.

---

## 9. Status state machine (cheat sheet)

| From | Trigger | To |
|---|---|---|
| (none) | `POST /onboarding/step1` | `pending_self_enrol` |
| `pending_self_enrol` | `POST /self-enrol/{token}/submit` (worker) | `pending_review` |
| `pending_self_enrol` | `POST /onboarding/{id}/complete` (admin kiosk) | `pending_review` |
| `pending_review` | `POST /workers/{id}/activate` | `active` |
| `pending_review` | `POST /workers/{id}/reject` | `rejected` |
| `rejected` | `POST /workers/{id}/issue-qr` | `pending_self_enrol` (with fresh token) |
| `active` | `POST /workers/{id}/block` | `blocked` |
| `blocked` | `POST /workers/{id}/unblock` | `active` |

**Cron eligibility:** only `status='active'` workers are picked up by the automated verification cron.

---

## 7. All current endpoints (inventory)

### Public (no auth)
| Method | Path |
|---|---|
| `POST` | `/webhooks/squad` (HMAC validated inside controller) |
| `POST` | `/webhooks/vapi` (X-Vapi-Secret header validated inside controller) |
| `GET`  | `/onboarding/resume/{token}` |
| `POST` | `/auth/login` |

### Sanctum-protected (everything below requires `Authorization: Bearer <token>`)

#### Auth
- `POST /auth/logout`, `GET /auth/me`

#### Dashboard / Reports
- `GET /dashboard/stats`
- `GET /reports/ghost-savings`
- `GET /reports/verification-rates`
- `GET /reports/cycle-summary/{cycle_id}`
- `GET /reports/at-risk-mdas`
- `GET /reports/ai-layer-performance`

#### Workers
- `GET /workers`, `POST /workers`, `GET /workers/{id}`, `PUT /workers/{id}`, `DELETE /workers/{id}`
- `POST /workers/import` (CSV)
- `GET /workers/{id}/verifications`
- `GET /workers/{id}/alerts`
- `POST /workers/{id}/block`, `POST /workers/{id}/unblock`
- `POST /workers/{id}/enrol-voice` ← biometric
- `POST /workers/{id}/verify-voice` ← biometric

#### MDAs / Departments
- `GET|POST /mdas`, `GET|PUT|DELETE /mdas/{id}`
- `GET /mdas/{id}/workers`, `GET /mdas/{id}/stats`
- `GET|POST /mdas/{mda_id}/departments`
- `PUT|DELETE /departments/{id}`

#### Verifications
- `GET /verifications`, `GET /verifications/{id}`
- `POST /verifications` (legacy — scores-only)
- `POST /verifications/{id}/override`

#### Face Verification (new) ← biometric
- `POST /face-verification/start`
- `POST /face-verification/{session_id}/frame1`
- `POST /face-verification/{session_id}/frame2`
- `POST /face-verification/{session_id}/frame3`
- `GET  /face-verification/{session_id}`

#### Cycles
- `GET|POST /cycles`, `GET /cycles/{id}`
- `POST /cycles/{id}/run`
- `GET /cycles/{id}/results`, `GET /cycles/{id}/summary`

#### Ghost-Worker Alerts (the `/ghost-worker` page)
- `GET /alerts?severity=high|medium|low&status=open`
- `GET /alerts/{id}`
- `POST /alerts/{id}/block`
- `POST /alerts/{id}/dispatch`
- `POST /alerts/{id}/refer-icpc`
- `POST /alerts/{id}/false-positive`
- `POST /alerts/{id}/resolve`

#### Field Agents / Dispatches
- `GET|POST /agents`, `GET|PUT /agents/{id}`
- `GET /agents/{id}/dispatches`
- `POST /agents/{id}/update-location`
- `GET /dispatches`, `GET /dispatches/{id}`
- `PUT /dispatches/{id}/complete`, `PUT /dispatches/{id}/fail`

#### Payments / Virtual Accounts / Settlements
- `GET /payments`, `GET /payments/{id}`
- `POST /payments/release`, `POST /payments/block/{worker_id}`
- `GET|POST /virtual-accounts`, `GET /virtual-accounts/{id}`, `DELETE /virtual-accounts/{id}`
- `GET /settlements`, `GET /settlements/{id}`
- `POST /settlements/{cycle_id}/initiate`

#### Admin
- `GET /audit-logs`, `GET /audit-logs/{id}`
- `GET /settings`, `PUT /settings`
- `GET|POST /users`, `GET|PUT|DELETE /users/{id}`

---

## 8. Static file URLs

The backend serves uploaded files at `http://127.0.0.1:8000/storage/...`:

| Path | What |
|---|---|
| `/storage/biometrics/faces/{worker_id}_{ts}.jpg` | Enrolled face photos |
| `/storage/biometrics/voices/{worker_id}_{ts}.wav` | Enrolled voice samples |
| `/storage/verifications/{worker_id}/{uuid}.wav` | Persisted call recordings |
| `/storage/face-sessions/{session_id}/frame{1,2,3}.{ext}` | Captured face-verification frames |

For face sessions, the URLs are also returned on the `face_verification_sessions` row as `frame1_url`, `frame2_url`, `frame3_url`.

---

## 9. Useful headers for every authenticated request

```http
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json     (or multipart/form-data for file uploads)
```

`Accept: application/json` is **important** — without it Laravel may return HTML error pages instead of JSON envelopes.

---

## 10. CORS

Configured by Laravel's default CORS middleware. If the frontend hits 403 / blocked-by-CORS, set `CORS_ALLOWED_ORIGINS` in the backend `.env` to include the frontend dev URL (e.g. `http://localhost:5173`).

---

## 11. Known limits / gotchas

1. **Voice verification is asynchronous.** The `/verify-voice` endpoint returns 202 immediately; the actual verdict arrives via webhook 15-60s later. Frontend should poll `/workers/{id}` or `/verifications?worker_id=X` for the result.
2. **Face verification is synchronous and stepped.** Each frame POST blocks for ~1-2s while the AI runs. Show a spinner during each upload.
3. **The voice verdict can be `INCONCLUSIVE`.** Treat as "needs manual review" — same UX as FAIL but route to a different review queue if you have one.
4. **Recording URLs are local public storage paths.** Prepend the backend host. Vapi's own `storage.vapi.ai/...` URLs are NOT what gets stored — we download and re-host.
5. **Image/audio file size limits:**
   - Voice enrol: 10 MB
   - Face enrol: no explicit limit (Laravel default ~10 MB)
   - Face frame: 5 MB
6. **No retries.** If a Vapi call fails (worker doesn't pick up) or a face frame is rejected, the admin must trigger again manually.
7. **Phone numbers must be E.164 format** (`+2348012345678`). Stored in `worker.phone`. Backend doesn't normalise — frontend should.

---

## 12. Quick test data after fresh seed

Seeded admin user:
- Email: `adamu.bello@ippis.gov.ng`
- Password: see `database/seeders/UserSeeder.php`

Seeded MDAs, departments, and one verification cycle are present.

To set up a test worker end-to-end for voice testing:
1. Login → get token
2. `POST /onboarding/step1` with required fields → get worker_id
3. `PUT /onboarding/{id}/step2` (personal details, set `phone` to a real number)
4. `PUT /onboarding/{id}/step3` (bank)
5. `POST /onboarding/{id}/step5` with a real WAV file → enrols voice
6. `POST /workers/{id}/verify-voice` → phone rings

For face testing, swap step5 for step4 with a JPEG, then call `/face-verification/start`.

---

## 13. Where to look in the code

| What | File |
|---|---|
| Voice endpoints | `app/Http/Controllers/Api/V1/VoiceEnrolmentController.php` |
| Face endpoints | `app/Http/Controllers/Api/V1/FaceVerificationController.php` |
| Vapi webhook | `app/Http/Controllers/Api/V1/Webhooks/VapiWebhookController.php` |
| AI HTTP client | `app/Services/AiVerificationService.php` |
| Vapi HTTP client | `app/Services/VapiCallService.php` |
| Trust scoring (legacy) | `app/Services/TrustScoreService.php` |
| Ghost alert creation | `app/Services/AlertService.php` |
| Routes | `routes/api.php` |
| Worker model | `app/Models/Worker.php` |
| Verification model | `app/Models/Verification.php` |
| Face session model | `app/Models/FaceVerificationSession.php` |
