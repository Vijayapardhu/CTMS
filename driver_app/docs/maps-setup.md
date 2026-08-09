# Google Maps setup — driver app

The app builds and runs without a key. The map surface will be blank and the
screen will say **"Map unavailable"**; tracking, position posting and the sync
queue are unaffected, because none of them go through Google from the client.

To make the map render, you need **one Android-restricted key**.

## Why not the backend key

`backend/.env` holds `GOOGLE_MAPS_API_KEY`. That is the **server** credential.
It carries the Routes, Route Matrix, Geocoding, Places and Roads entitlements
that CTMS bills against, and the backend is the only thing that should ever
hold it.

Anything shipped inside an APK can be read out of it — `strings` on the binary
is enough. Putting the server key in the app would hand its quota to anyone who
downloads the build. A server key is also commonly IP-restricted, and an IP
restriction makes the Maps SDK fail outright on a driver's mobile connection.

So: two keys, in the same Cloud project, with different restrictions.

## Creating the Android key

In Google Cloud Console → **APIs & Services → Credentials → Create credentials
→ API key**:

1. **API restrictions** — restrict the key, and enable **only**
   `Maps SDK for Android`. Nothing else. The client needs no other Google API;
   routing, snapping, geocoding and the Route Matrix all happen server-side.
2. **Application restrictions** — choose **Android apps**, and add the package
   name with each signing certificate you build with:

   | Package | Certificate |
   |---|---|
   | `com.ctms.ctms_driver` | debug SHA-1 |
   | `com.ctms.ctms_driver` | release SHA-1 |

Get the debug fingerprint with:

```bash
keytool -list -v \
  -keystore ~/.android/debug.keystore \
  -alias androiddebugkey -storepass android -keypass android
```

For the release fingerprint, run the same command against the keystore named in
`android/key.properties`.

## Installing it

Put it in `android/local.properties`, which is gitignored:

```properties
MAPS_API_KEY=AIza...
```

`android/app/build.gradle.kts` reads that file and substitutes the value into
the manifest placeholder at merge time, so the key reaches the APK without ever
reaching the repository. CI has no key and only builds debug, which is why the
absent case has to keep working.

Rebuild after changing it — Gradle reads `local.properties` at configuration
time, so a hot reload will not pick it up.

## Cloud services this prototype needs

Enable on the **server** key:

- Routes API — route calculation and geometry
- Geocoding API — stop addresses
- Places API — stop resolution in management tooling
- Roads API — snapping incoming GPS to the road network

Enable on the **Android** key:

- Maps SDK for Android

Nothing else. In particular the Navigation SDK is not used: the driver app does
not do turn-by-turn.

## Verifying

With the key in place, start a trip on a device and open the Map tab. You
should see the route line through the CTMS stops, coloured stop markers, and
the bus once the first position has completed the round trip through the
backend.

If the map stays blank, check `adb logcat -s Google Maps Android API` — an
authorisation failure names the reason, and the usual cause is a fingerprint
that does not match the certificate the build was signed with.
