plugins {
    id("com.android.library") version "8.7.3"
    kotlin("android") version "2.0.21"
}

// Optional: an app whose registration uses Play Integrity (observe or
// require) adds com.prosper202:integrity beside com.prosper202:android and
// passes PlayIntegrityProvider(context) as Options.integrity. Apps that do
// not keep the base SDK's single dependency (plan §5.6).
group = "com.prosper202"
version = "1.0.0"

android {
    namespace = "com.prosper202.attribution.integrity"
    compileSdk = 34

    defaultConfig {
        // Play Integrity's own floor.
        minSdk = 21
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }

    kotlinOptions {
        jvmTarget = "1.8"
    }
}

dependencies {
    api(project(":core"))
    // The standard request (StandardIntegrityManager), 1.6.0, the latest release.
    implementation("com.google.android.play:integrity:1.6.0")
}
