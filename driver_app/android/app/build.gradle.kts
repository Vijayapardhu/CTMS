import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// Release signing is read from android/key.properties, which is not committed.
// When the file is absent — every developer machine and CI check that only
// needs a debug build — the release type falls back to the debug keys so the
// build still succeeds. A release APK signed with debug keys cannot be
// uploaded to Play, so this cannot ship by accident.
val keystoreProperties = Properties().apply {
    val file = rootProject.file("key.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}
val hasReleaseKeystore = keystoreProperties.getProperty("storeFile") != null

// The Android-restricted Maps SDK key, read from android/local.properties —
// which is gitignored, so the key reaches the manifest without ever reaching
// the repository. This is NOT the server key: the backend's Routes API
// credential stays in backend/.env and never enters an APK, where it could be
// extracted from the binary.
//
// Absent on a machine that has not been given one: the build still succeeds and
// the map surface renders blank with an authorisation failure in logcat, which
// is a far better failure than a build that cannot be run at all. CI checks a
// debug build and has no key.
val mapsApiKey: String = Properties().apply {
    val file = rootProject.file("local.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}.getProperty("GOOGLE_MAPS_ANDROID_API_KEY") ?: ""


android {
    namespace = "com.ctms.ctms_driver"

    // Pinned rather than taken from `flutter.compileSdkVersion`: androidx.core
    // 1.18 requires 36, and a plugin upgrade silently raising this is how a
    // build starts failing for reasons unrelated to the change that caused it.
    compileSdk = 36

    // Pinned to the highest version any plugin asks for. NDK releases are
    // backward compatible, so the highest wins; leaving it at Flutter's default
    // makes every build print a five-line plugin mismatch warning.
    ndkVersion = "27.0.12077973"

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_11.toString()
    }

    defaultConfig {
        applicationId = "com.ctms.ctms_driver"

        // 23 is the floor for flutter_secure_storage's cipher-backed storage.
        // Below it, tokens would fall back to plain shared preferences.
        minSdk = 23
        targetSdk = 36
        versionCode = flutter.versionCode
        versionName = flutter.versionName

        // Substituted into the `com.google.android.geo.API_KEY` meta-data at
        // merge time, so no committed file ever holds the value.
        manifestPlaceholders["GOOGLE_MAPS_ANDROID_API_KEY"] = mapsApiKey
    }

    signingConfigs {
        if (hasReleaseKeystore) {
            create("release") {
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
                storeFile = file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
            }
        }
    }

    buildTypes {
        release {
            signingConfig = if (hasReleaseKeystore) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }
}

flutter {
    source = "../.."
}
