BINARY := p202
VERSION := 2.0.0
BUILD_DIR := dist

PLATFORMS := linux/amd64 linux/arm64 darwin/amd64 darwin/arm64 windows/amd64 windows/arm64

LDFLAGS := -s -w -X main.version=$(VERSION)

.PHONY: build clean all test

build:
	go build -ldflags "$(LDFLAGS)" -o $(BINARY) .

all: clean
	@mkdir -p $(BUILD_DIR)
	@for platform in $(PLATFORMS); do \
		os=$$(echo $$platform | cut -d/ -f1); \
		arch=$$(echo $$platform | cut -d/ -f2); \
		ext=""; \
		if [ "$$os" = "windows" ]; then ext=".exe"; fi; \
		outfile="$(BUILD_DIR)/$(BINARY)-$$os-$$arch$$ext"; \
		echo "Building $$outfile..."; \
		CGO_ENABLED=0 GOOS=$$os GOARCH=$$arch go build -ldflags "$(LDFLAGS)" -o $$outfile . || exit 1; \
	done
	@echo "All builds complete in $(BUILD_DIR)/"

clean:
	rm -rf $(BUILD_DIR) $(BINARY)

test:
	go vet ./...
