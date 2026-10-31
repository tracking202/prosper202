plugins {
    id("com.android.library")
    kotlin("android")
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
    // The only third-party dependency (plan §5.6): Play's install-referrer
    // client, 2.2 (January 2021), the latest release.
    implementation("com.android.installreferrer:installreferrer:2.2")

    testImplementation(kotlin("test"))
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.14.1")
}

apply(from = rootProject.file("robolectric.gradle"))
