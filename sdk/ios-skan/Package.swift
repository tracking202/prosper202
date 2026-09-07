// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "P202SKAN",
    platforms: [
        .iOS(.v14),
        .macOS(.v11),
    ],
    products: [
        .library(name: "P202SKAN", targets: ["P202SKAN"]),
    ],
    targets: [
        .target(name: "P202SKAN", path: "Sources/P202SKAN"),
        .testTarget(
            name: "P202SKANTests",
            dependencies: ["P202SKAN"],
            path: "Tests/P202SKANTests"
        ),
    ]
)
