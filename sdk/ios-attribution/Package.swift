// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "P202Attribution",
    platforms: [
        .iOS(.v14),
        .macOS(.v11),
    ],
    products: [
        .library(name: "P202Attribution", targets: ["P202Attribution"]),
    ],
    targets: [
        .target(name: "P202Attribution", path: "Sources/P202Attribution"),
        .testTarget(
            name: "P202AttributionTests",
            dependencies: ["P202Attribution"],
            path: "Tests/P202AttributionTests"
        ),
    ]
)
