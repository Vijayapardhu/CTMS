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
