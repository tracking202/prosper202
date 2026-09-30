// The plugins' versions, declared once. With the Kotlin plugin versioned in
// both :core (jvm) and :android/:integrity (android), Gradle loaded it into
// each project's own classloader and warned that it "was loaded multiple
// times ... may break the build" — the first thing the first AGP assembly of
// this SDK printed. Subprojects apply these unversioned.
plugins {
    id("com.android.library") version "8.7.3" apply false
    kotlin("android") version "2.0.21" apply false
    kotlin("jvm") version "2.0.21" apply false
    id("ru.vyarus.animalsniffer") version "2.0.1" apply false
}
