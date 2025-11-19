# Attribution Export Feature Plan

- [x] Define database schema for export jobs and webhook metadata in installation and upgrade scripts.
- [x] Introduce export job domain objects, repositories, and service APIs to enqueue and manage exports.
- [x] Extend API routing and controller layers to schedule exports with validation and option normalization.
- [x] Implement cronjob worker to process export queue, generate files, update job status, and trigger webhooks.
- [x] Update account UI to surface export controls and document workflow in advanced attribution guide.
- [x] Add PHPUnit coverage for scheduling and completion paths using repository stubs.
- [x] Register cronjob in scheduler configuration.
