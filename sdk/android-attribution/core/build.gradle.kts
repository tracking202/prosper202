import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    kotlin("jvm")
    // The core runs on Android from API 21, and Android lint does not reach it:
    // lint's NewApi check skips a plain JVM module, even as a dependency of an
    // Android one with checkDependencies (tried: a planted java.util.Base64
    // call in :core was reported by neither). Animal Sniffer checks the
    // compiled classes against the API-21 signature instead; it is what found
    // EventPayload's Map.putIfAbsent (API 24, not backported by D8).
    id("ru.vyarus.animalsniffer")
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

animalsniffer {
    // D8 backports these classes' Java 8 static helpers (Long.hashCode(long)
    // and the like, which Kotlin emits for data classes) when it dexes the
    // app for minSdk < 24. Animal Sniffer's ignores match the owner class
    // only (animal-sniffer 1.24's SignatureChecker has no member form), so
    // the three are ignored whole here, and the trust is enforced where the
    // backport happens: CI's Android job dexes :core with D8 at --min-api 21
    // and scripts/dex-api-check.py fails on any platform method, field or
    // class in the dex that is newer than API 21, on these classes or any
    // other.
    ignore = listOf("java.lang.Boolean", "java.lang.Long", "java.lang.Double")
}

// The core's runtime dependencies (the Kotlin standard library), for D8's
// --classpath in that CI step: they are not dexed or checked, only resolved.
tasks.register<Sync>("dexClasspath") {
    from(configurations.runtimeClasspath)
    into(layout.buildDirectory.dir("dex-classpath"))
}

dependencies {
    // Android API 21 (the SDK's minSdk) as an Animal Sniffer signature.
    signature("com.toasttab.android:gummy-bears-api-21:0.15.0@signature")
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
