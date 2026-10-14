#!/usr/bin/env python3
"""Register HiTechPay Vietnamese connectors inside a Hyperswitch checkout.

Every registry point that references the reference connector (``paystack``)
gets a sibling entry for each new connector. The script is idempotent: lines
that already exist are not duplicated.

Usage: tools/register_connectors.py <path-to-hyperswitch>
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

REF = "paystack"
REF_CAMEL = "Paystack"

# name, Camel, sandbox base_url, production base_url, secondary(sandbox), secondary(prod)
CONNECTORS = [
    ("momo", "Momo", "https://test-payment.momo.vn/", "https://payment.momo.vn/", None, None),
    ("pay2s", "Pay2s", "https://my.pay2s.vn/", "https://my.pay2s.vn/", None, None),
    ("payos", "Payos", "https://api-merchant.payos.vn/", "https://api-merchant.payos.vn/", None, None),
    ("sepay", "Sepay", "https://my.sepay.vn/", "https://my.sepay.vn/", None, None),
    # Redirect gateways: base_url is the server-to-server API endpoint. The hosted payment
    # page URL is chosen from the connector account metadata / test mode (see connector docs).
    (
        "viettelmoney",
        "Viettelmoney",
        "https://sandbox.viettel.vn/PaymentAPI/webresources/postData",
        "https://pay3.viettel.vn/PaymentAPI/webresources/postData",
        None,
        None,
    ),
    (
        "vnpay",
        "Vnpay",
        "https://sandbox.vnpayment.vn/merchant_webapi/api/transaction",
        "https://merchant.vnpay.vn/merchant_webapi/api/transaction",
        None,
        None,
    ),
    ("zalopay", "Zalopay", "https://sb-openapi.zalopay.vn/", "https://openapi.zalopay.vn/", None, None),
]

PM_FILTERS = {
    "momo": [
        'momo = { country = "VN", currency = "VND" }',
        'momo_atm = { country = "VN", currency = "VND" }',
    ],
    "pay2s": ['viet_qr = { country = "VN", currency = "VND" }'],
    "payos": ['viet_qr = { country = "VN", currency = "VND" }'],
    "sepay": ['viet_qr = { country = "VN", currency = "VND" }'],
    "viettelmoney": [
        'local_bank_redirect = { country = "VN", currency = "VND" }',
        'viet_qr = { country = "VN", currency = "VND" }',
    ],
    "vnpay": [
        'local_bank_redirect = { country = "VN", currency = "VND" }',
        'viet_qr = { country = "VN", currency = "VND" }',
    ],
    "zalopay": [
        'local_bank_redirect = { country = "VN", currency = "VND" }',
        'viet_qr = { country = "VN", currency = "VND" }',
    ],
}

# Dashboard (control center) connector configuration.
CONNECTOR_TOML = {
    "momo": """[momo]
[[momo.wallet]]
payment_method_type = "momo"
[[momo.card_redirect]]
payment_method_type = "momo_atm"
[momo.connector_auth.SignatureKey]
api_key = "Partner Code"
key1 = "Access Key"
api_secret = "Secret Key"
[momo.connector_webhook_details]
merchant_secret = "Secret Key"
""",
    "pay2s": """[pay2s]
[[pay2s.real_time_payment]]
payment_method_type = "viet_qr"
[pay2s.connector_auth.SignatureKey]
api_key = "API Token"
key1 = "Bank Account Number"
api_secret = "Bank Code (VietQR BIN or short name, e.g. MB)"
[pay2s.connector_webhook_details]
merchant_secret = "Webhook Token"
[pay2s.metadata.account_name]
name = "account_name"
label = "Bank Account Holder Name"
placeholder = "Enter account holder name shown on the VietQR"
required = false
type = "Text"
""",
    "payos": """[payos]
[[payos.real_time_payment]]
payment_method_type = "viet_qr"
[payos.connector_auth.SignatureKey]
api_key = "Client ID"
key1 = "API Key"
api_secret = "Checksum Key"
[payos.connector_webhook_details]
merchant_secret = "Checksum Key"
""",
    "sepay": """[sepay]
[[sepay.real_time_payment]]
payment_method_type = "viet_qr"
[sepay.connector_auth.SignatureKey]
api_key = "API Token"
key1 = "Bank Account Number"
api_secret = "Bank Short Name (e.g. MBBank, Vietcombank)"
[sepay.connector_webhook_details]
merchant_secret = "Webhook API Key"
""",
    "viettelmoney": """[viettelmoney]
[[viettelmoney.bank_redirect]]
payment_method_type = "local_bank_redirect"
[[viettelmoney.real_time_payment]]
payment_method_type = "viet_qr"
[viettelmoney.connector_auth.SignatureKey]
api_key = "Merchant Code"
key1 = "Access Code"
api_secret = "Hash Key"
[viettelmoney.connector_webhook_details]
merchant_secret = "Hash Key"
""",
    "vnpay": """[vnpay]
[[vnpay.bank_redirect]]
payment_method_type = "local_bank_redirect"
[[vnpay.real_time_payment]]
payment_method_type = "viet_qr"
[vnpay.connector_auth.BodyKey]
api_key = "TMN Code"
key1 = "Hash Secret"
[vnpay.connector_webhook_details]
merchant_secret = "Hash Secret"
""",
    "zalopay": """[zalopay]
[[zalopay.bank_redirect]]
payment_method_type = "local_bank_redirect"
[[zalopay.real_time_payment]]
payment_method_type = "viet_qr"
[zalopay.connector_auth.SignatureKey]
api_key = "App ID"
key1 = "Key1"
api_secret = "Key2"
[zalopay.connector_webhook_details]
merchant_secret = "Key2"
""",
}

SAMPLE_AUTH = {
    "momo": 'api_key = "Partner Code"\nkey1 = "Access Key"\napi_secret = "Secret Key"',
    "pay2s": 'api_key = "API Token"\nkey1 = "Account Number"\napi_secret = "Bank Code"',
    "payos": 'api_key = "Client ID"\nkey1 = "API Key"\napi_secret = "Checksum Key"',
    "sepay": 'api_key = "API Token"\nkey1 = "Account Number"\napi_secret = "Bank Short Name"',
    "viettelmoney": 'api_key = "Merchant Code"\nkey1 = "Access Code"\napi_secret = "Hash Key"',
    "vnpay": 'api_key = "TMN Code"\nkey1 = "Hash Secret"',
    "zalopay": 'api_key = "App ID"\nkey1 = "Key1"\napi_secret = "Key2"',
}

AUTH_KIND = {name: ("BodyKey" if name == "vnpay" else "SignatureKey") for name, *_ in CONNECTORS}

BASE_URL_FILES_SANDBOX = [
    "config/config.example.toml",
    "config/development.toml",
    "config/docker_compose.toml",
    "config/deployments/integration_test.toml",
    "config/deployments/sandbox.toml",
    "loadtest/config/development.toml",
]
BASE_URL_FILES_PROD = ["config/deployments/production.toml"]

# Files where every line mentioning the reference connector is duplicated
# (enum variants, match arms, module declarations, struct fields, lists).
LINE_DUP_FILES = [
    "crates/common_enums/src/connector_enums.rs",
    "crates/connector_configs/src/connector.rs",
    "crates/euclid/src/enums.rs",
    "crates/hyperswitch_connectors/src/connectors.rs",
    "crates/hyperswitch_connectors/src/default_implementations.rs",
    "crates/hyperswitch_connectors/src/default_implementations_v2.rs",
    "crates/hyperswitch_domain_models/src/connector_endpoints.rs",
    "crates/router/src/types/connector_transformers.rs",
    "crates/router/tests/connectors/main.rs",
    "config/development.toml",
    "config/docker_compose.toml",
    "config/config.example.toml",
    "config/superposition_seed.toml",
    "loadtest/config/development.toml",
]

# Files with multi-line `Connector::Paystack => { ... }` blocks.
BLOCK_DUP_FILES = [
    "crates/router/src/core/connector_validation.rs",
    "crates/router/src/types/api/connector_mapping.rs",
    "crates/router/src/types/api/feature_matrix.rs",
]

# `use` lists that contain many connectors on one line.
USE_LIST_FILES = [
    "crates/hyperswitch_connectors/src/connectors.rs",
    "crates/router/src/connector.rs",
]

PM_FILTER_FILES = [
    "config/development.toml",
    "config/docker_compose.toml",
    "config/deployments/production.toml",
    "config/deployments/sandbox.toml",
    "config/deployments/integration_test.toml",
    "loadtest/config/development.toml",
]

CONNECTOR_TOML_FILES = [
    "crates/connector_configs/toml/development.toml",
    "crates/connector_configs/toml/sandbox.toml",
    "crates/connector_configs/toml/production.toml",
]

WORD_RE = re.compile(r"\b(paystack|Paystack|PAYSTACK)(?=AuthType\b|\b)")


def rename(line: str, name: str, camel: str) -> str:
    return WORD_RE.sub(
        lambda m: {"paystack": name, "Paystack": camel, "PAYSTACK": name.upper()}[m.group(1)],
        line,
    )


def is_single_connector_line(line: str) -> bool:
    """A line that mentions the reference connector and nothing else of note."""
    if "base_url" in line:
        return False
    stripped = line.strip()
    if stripped.startswith("[pm_filters"):
        return False
    # skip long `use` lists (handled separately)
    if stripped.count("::") > 2 and "," in stripped and stripped.count(",") > 1:
        return False
    return True


def dup_lines(path: Path) -> None:
    lines = path.read_text().split("\n")
    out: list[str] = []
    window = len(CONNECTORS) + 1
    for idx, line in enumerate(lines):
        out.append(line)
        if WORD_RE.search(line) and is_single_connector_line(line):
            # only look at the neighbourhood: the same line legitimately appears in many
            # macro invocations / lists, each needs its own entry
            nearby = set(lines[max(0, idx - window) : idx + window + 1])
            for name, camel, *_ in CONNECTORS:
                new = rename(line, name, camel)
                if new not in nearby:
                    out.append(new)
    path.write_text("\n".join(out))


def dup_blocks(path: Path) -> None:
    lines = path.read_text().split("\n")
    out: list[str] = []
    i = 0
    while i < len(lines):
        line = lines[i]
        if re.search(r"Connector::Paystack\s*=>\s*\{\s*$", line):
            indent = len(line) - len(line.lstrip())
            block = [line]
            j = i + 1
            while j < len(lines):
                block.append(lines[j])
                if lines[j].strip() == "}" and len(lines[j]) - len(lines[j].lstrip()) == indent:
                    break
                j += 1
            out.extend(block)
            joined_existing = "\n".join(lines)
            for name, camel, *_ in CONNECTORS:
                new_block = [rename(b, name, camel) for b in block]
                if new_block[0] not in joined_existing:
                    out.extend(new_block)
            i = j + 1
            continue
        out.append(line)
        i += 1
    path.write_text("\n".join(out))


def extend_use_lists(path: Path) -> None:
    text = path.read_text()
    if "paystack::Paystack" not in text:
        return
    if "connectors.rs" in str(path) and "hyperswitch_connectors" in str(path):
        additions = " ".join(f"{n}::{c}," for n, c, *_ in CONNECTORS if f"{n}::{c}" not in text)
        if not additions:
            return
        text = text.replace("paystack::Paystack,", f"paystack::Paystack, {additions}", 1)
    else:
        additions = " ".join(
            f"{n}, {n}::{c}," for n, c, *_ in CONNECTORS if f"{n}::{c}" not in text
        )
        if not additions:
            return
        text = text.replace("paystack, paystack::Paystack,", f"paystack, paystack::Paystack, {additions}", 1)
    path.write_text(text)


def add_base_urls(path: Path, production: bool) -> None:
    lines = path.read_text().split("\n")
    out: list[str] = []
    text = "\n".join(lines)
    for line in lines:
        out.append(line)
        if line.startswith("paystack.base_url"):
            for name, _camel, sb, prod, sec_sb, sec_prod in CONNECTORS:
                url = prod if production else sb
                entry = f'{name}.base_url = "{url}"'
                if f"{name}.base_url" not in text:
                    out.append(entry)
                sec = sec_prod if production else sec_sb
                if sec and f"{name}.secondary_base_url" not in text:
                    out.append(f'{name}.secondary_base_url = "{sec}"')
    path.write_text("\n".join(out))


def add_pm_filters(path: Path) -> None:
    text = path.read_text()
    marker = "[pm_filters.paystack]"
    if marker not in text:
        return
    blocks = []
    for name, *_ in CONNECTORS:
        header = f"[pm_filters.{name}]"
        if header in text:
            continue
        blocks.append(header + "\n" + "\n".join(PM_FILTERS[name]) + "\n")
    if not blocks:
        return
    idx = text.index(marker)
    text = text[:idx] + "\n".join(blocks) + "\n" + text[idx:]
    path.write_text(text)


def add_connector_toml(path: Path) -> None:
    text = path.read_text()
    for name, *_ in CONNECTORS:
        if f"\n[{name}]\n" in text:
            continue
        text = text.rstrip("\n") + "\n\n" + CONNECTOR_TOML[name]
    path.write_text(text)


def add_sample_auth(path: Path) -> None:
    text = path.read_text()
    for name, *_ in CONNECTORS:
        if f"\n[{name}]\n" in text:
            continue
        text = text.rstrip("\n") + f"\n\n[{name}]\n{SAMPLE_AUTH[name]}\n"
    path.write_text(text)


def add_test_utils_auth(path: Path) -> None:
    lines = path.read_text().split("\n")
    text = "\n".join(lines)
    out = []
    for line in lines:
        out.append(line)
        if re.match(r"\s*pub paystack: Option<HeaderKey>,", line):
            for name, *_ in CONNECTORS:
                if f"pub {name}:" not in text:
                    kind = AUTH_KIND[name]
                    out.append(f"    pub {name}: Option<{kind}>,")
    path.write_text("\n".join(out))


def already_registered(root: Path) -> set[str]:
    """Connectors whose enum variant exists already (e.g. patches applied + rustfmt'd)."""
    enums = (root / "crates/common_enums/src/connector_enums.rs").read_text()
    return {name for name, camel, *_ in CONNECTORS if re.search(rf"^\s+{camel},\s*$", enums, re.M)}


def main() -> None:
    global CONNECTORS
    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    done = already_registered(root)
    all_connectors = CONNECTORS
    # source-code registry points are only touched for new connectors; config files below are
    # checked key by key and stay idempotent on their own
    CONNECTORS = [c for c in all_connectors if c[0] not in done]
    for f in USE_LIST_FILES:
        extend_use_lists(root / f)
    for f in LINE_DUP_FILES:
        dup_lines(root / f)
    for f in BLOCK_DUP_FILES:
        dup_blocks(root / f)
    CONNECTORS = all_connectors
    for f in BASE_URL_FILES_SANDBOX:
        add_base_urls(root / f, production=False)
    for f in BASE_URL_FILES_PROD:
        add_base_urls(root / f, production=True)
    for f in PM_FILTER_FILES:
        add_pm_filters(root / f)
    for f in CONNECTOR_TOML_FILES:
        add_connector_toml(root / f)
    add_sample_auth(root / "crates/router/tests/connectors/sample_auth.toml")
    add_test_utils_auth(root / "crates/test_utils/src/connector_auth.rs")
    new = [n for n, *_ in all_connectors if n not in done]
    print("registered:", ", ".join(new) if new else "nothing new (already registered)")


if __name__ == "__main__":
    main()
