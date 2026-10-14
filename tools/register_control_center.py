#!/usr/bin/env python3
"""Register HiTechPay Vietnamese connectors in hyperswitch-control-center (dashboard).

Adds the connector variants, display names, descriptions and icons next to the
reference connector (Paystack). Idempotent.

Usage: tools/register_control_center.py <path-to-hyperswitch-control-center>
"""

from __future__ import annotations

import sys
from pathlib import Path

# name, VARIANT, display name, description, brand colour, icon label
CONNECTORS = [
    (
        "momo",
        "MOMO",
        "MoMo",
        "MoMo is Vietnam's leading e-wallet with more than 30 million users. Customers pay by scanning a QR code or confirming in the MoMo app, or with domestic ATM cards through the MoMo gateway.",
        "#A50064",
        "MoMo",
    ),
    (
        "vnpay",
        "VNPAY",
        "VNPAY",
        "VNPAY payment gateway accepts VNPAY-QR from 40+ banking apps, domestic ATM cards (NAPAS) and international cards for merchants in Vietnam.",
        "#005BAA",
        "VNPAY",
    ),
    (
        "zalopay",
        "ZALOPAY",
        "ZaloPay",
        "ZaloPay is a Vietnamese e-wallet integrated with Zalo. Customers pay through the ZaloPay app, VietQR or domestic cards.",
        "#0068FF",
        "Zalo",
    ),
    (
        "viettelmoney",
        "VIETTELMONEY",
        "Viettel Money",
        "Viettel Money (ViettelPay) is the mobile money and e-wallet service of Viettel, enabling payments from Viettel Money accounts and linked bank accounts.",
        "#EE0033",
        "VTM",
    ),
    (
        "payos",
        "PAYOS",
        "payOS",
        "payOS lets Vietnamese businesses collect payments via VietQR bank transfers directly into their own bank account with instant confirmation.",
        "#00A86B",
        "payOS",
    ),
    (
        "sepay",
        "SEPAY",
        "SePay",
        "SePay automates bank transfer reconciliation in Vietnam: customers pay by VietQR and SePay notifies HiTechPay as soon as the money arrives.",
        "#2E6FF2",
        "SePay",
    ),
    (
        "pay2s",
        "PAY2S",
        "Pay2S",
        "Pay2S provides VietQR bank transfer collection with automatic transaction notifications for Vietnamese merchants.",
        "#F7931E",
        "Pay2S",
    ),
]


def insert_after(text: str, anchor: str, lines: list[str]) -> str:
    if anchor not in text:
        raise SystemExit(f"anchor not found: {anchor!r}")
    new = [line for line in lines if line not in text]
    if not new:
        return text
    return text.replace(anchor, anchor + "\n" + "\n".join(new), 1)


def icon_svg(color: str, label: str) -> str:
    size = 9 if len(label) <= 4 else 7
    return f"""<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
<rect x="0.4" y="0.4" width="31.2" height="31.2" rx="2.8" fill="{color}"/>
<text x="16" y="19.5" text-anchor="middle" font-family="Inter, Arial, sans-serif" font-size="{size}" font-weight="700" fill="#FFFFFF">{label}</text>
</svg>
"""


def main() -> None:
    root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
    types_path = root / "src/screens/Connectors/ConnectorTypes.res"
    utils_path = root / "src/screens/Connectors/ConnectorUtils.res"

    types = types_path.read_text()
    types = insert_after(types, "  | PAYSTACK", [f"  | {v}" for _, v, *_ in CONNECTORS])
    types_path.write_text(types)

    utils = utils_path.read_text()
    utils = insert_after(
        utils, "  Processors(PAYSTACK),", [f"  Processors({v})," for _, v, *_ in CONNECTORS]
    )
    info_blocks = "\n".join(
        f'\nlet {n}Info = {{\n  description: "{d}",\n}}' for n, _v, _dn, d, *_ in CONNECTORS
        if f"let {n}Info" not in utils
    )
    if info_blocks:
        anchor = utils.index("let paystackInfo = {")
        end = utils.index("}", anchor) + 1
        utils = utils[:end] + "\n" + info_blocks + utils[end:]
    utils = insert_after(
        utils, '  | PAYSTACK => "paystack"', [f'  | {v} => "{n}"' for n, v, *_ in CONNECTORS]
    )
    utils = insert_after(
        utils,
        '    | "paystack" => Processors(PAYSTACK)',
        [f'    | "{n}" => Processors({v})' for n, v, *_ in CONNECTORS],
    )
    utils = insert_after(
        utils, "  | PAYSTACK => paystackInfo", [f"  | {v} => {n}Info" for n, v, *_ in CONNECTORS]
    )
    utils = insert_after(
        utils, '  | PAYSTACK => "Paystack"', [f'  | {v} => "{dn}"' for _n, v, dn, *_ in CONNECTORS]
    )
    utils_path.write_text(utils)

    gateway = root / "public/hyperswitch/assets/Gateway"
    for _n, v, _dn, _d, color, label in CONNECTORS:
        (gateway / f"{v}.svg").write_text(icon_svg(color, label))

    print("control center: registered", ", ".join(n for n, *_ in CONNECTORS))


if __name__ == "__main__":
    main()
