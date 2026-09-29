plugins {
    id("com.android.library")
    kotlin("android")
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
        // Play Integrity's own floor: integrity 1.6.0's manifest declares
        // minSdkVersion 23. This said 21 until the module's first AGP build
        // (its unit-test manifest merge) refused it — as every app with
        // minSdk 21 or 22 that added the module would have been refused.
        minSdk = 23
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_1_8
        targetCompatibility = JavaVersion.VERSION_1_8
    }

    kotlinOptions {
        jvmTarget = "1.8"
        allWarningsAsErrors = true
    }

    lint {
        abortOnError = true
        warningsAsErrors = true
    }

    testOptions {
        // Robolectric: the real Android framework classes on the JVM.
        unitTests.isIncludeAndroidResources = true
    }
}

dependencies {
    api(project(":core"))
    // The standard request (StandardIntegrityManager), 1.6.0, the latest release.
    implementation("com.google.android.play:integrity:1.6.0")

    testImplementation(kotlin("test"))
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.14.1")
}

apply(from = rootProject.file("robolectric.gradle"))
