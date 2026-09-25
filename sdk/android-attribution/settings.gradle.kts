pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositories {
        google()
        mavenCentral()
    }
}

rootProject.name = "p202-android-attribution"

// The platform-free core (token, bodies, canonical form, queue, retries,
// transport) and its contract-vector tests need only a JDK.
include(":core")

// The Android library needs an Android SDK. It is included when one is found
// (ANDROID_HOME, ANDROID_SDK_ROOT, or sdk.dir in local.properties) unless
// -Pp202.android=false says not to — which CI's JVM job does, because the
// runner image ships an SDK and the core must not wait on AGP.
val localSdk = file("local.properties").takeIf { it.exists() }?.readLines()
    ?.firstOrNull { it.startsWith("sdk.dir=") }?.substringAfter("sdk.dir=")
val androidSdk = listOf(System.getenv("ANDROID_HOME"), System.getenv("ANDROID_SDK_ROOT"), localSdk)
    .firstOrNull { !it.isNullOrBlank() }
val wanted = providers.gradleProperty("p202.android").orNull != "false"
if (wanted && androidSdk != null && File(androidSdk).isAbsolute && File(androidSdk).isDirectory) {
    include(":android")
}
