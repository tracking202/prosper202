import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    kotlin("jvm") version "2.0.21"
}

group = "com.prosper202"
version = "1.0.0"

java {
    // Android's D8 reads Java 8 bytecode on every API level the SDK supports (minSdk 21).
    sourceCompatibility = JavaVersion.VERSION_1_8
    targetCompatibility = JavaVersion.VERSION_1_8
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_1_8)
        allWarningsAsErrors.set(true)
    }
}

dependencies {
    testImplementation(kotlin("test"))
    testImplementation("junit:junit:4.13.2")
}

tasks.test {
    // The cross-language vectors every implementation runs (plan §4.3).
    systemProperty("p202.contractDir", rootProject.file("../../tests/fixtures/app-sdk-contract").absolutePath)
    // The live pass (tests/live/android-sdk.sh) hands the running instance in.
    for (name in listOf("P202_LIVE_BASE", "P202_LIVE_APP_TOKEN", "P202_LIVE_APP_KEY", "P202_LIVE_REFERRER", "P202_LIVE_CLICK_TIME",
            "P202_LIVE_CUSTOMER_ID", "P202_LIVE_CUSTOMER_SIG", "P202_LIVE_OUT",
            "P202_LIVE_PHASE", "P202_LIVE_CLOUD_PROJECT", "P202_LIVE_STORE")) {
        System.getenv(name)?.let { environment(name, it) }
    }
    testLogging {
        events("failed", "skipped")
        showStandardStreams = System.getenv("P202_LIVE_BASE") != null
        exceptionFormat = org.gradle.api.tasks.testing.logging.TestExceptionFormat.FULL
    }
}
