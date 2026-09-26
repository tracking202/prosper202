plugins {
    id("com.android.library") version "8.7.3"
    kotlin("android") version "2.0.21"
}

// An app includes this build (includeBuild) and depends on com.prosper202:android.
group = "com.prosper202"
version = "1.0.0"

android {
    namespace = "com.prosper202.attribution"
    compileSdk = 34

    defaultConfig {
        minSdk = 21
        consumerProguardFiles("consumer-rules.pro")
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
    // The only third-party dependency (plan §5.6): Play's install-referrer
    // client, 2.2 (January 2021), the latest release.
    implementation("com.android.installreferrer:installreferrer:2.2")
}
