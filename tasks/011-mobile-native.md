# 011 — Mobile: build Android/iOS, test thiết bị, wrapper branding

Trạng thái: Chưa làm
Ưu tiên: P1

## Bối cảnh
`hyperswitch-client-core` (patch `patches/hyperswitch-client-core/`) đã có tiếng Việt, VietQR (màn hình QR mới, polling 3s),
MoMo ATM, ngân hàng nội địa, branding; mới kiểm tra bằng ReScript build. Các wrapper `hyperswitch-sdk-android`,
`hyperswitch-sdk-ios`, `react-native-hyperswitch` **chưa vá** (tên hiển thị, package, publish).

## Các bước
1. Build demo app Android (Gradle) & iOS (Xcode/CocoaPods) dùng client-core đã vá; chạy trên thiết bị thật.
2. Test các phương thức VN với backend sandbox; màn hình QR (hết hạn, copy, "Tôi đã thanh toán").
3. Áp dụng body `*_redirect` cho các ví redirect khác nếu cần (`HiTechPayPaymentMethods.res`).
4. Quyết định cách phát hành: fork wrapper thành `hitechpay-sdk-android/ios` (đổi groupId/bundle) hay giữ upstream + config.
   Đưa vào `PATCHED_REPOS` nếu vá.
5. Jest/Detox của client-core trong CI.

## Tiêu chí hoàn thành
- APK/IPA demo thanh toán được VietQR & MoMo trên sandbox; hướng dẫn tích hợp mobile trong docs.
