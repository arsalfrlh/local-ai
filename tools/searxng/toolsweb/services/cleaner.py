def clean_text(text):
    if not text:
        return None

    bad_words = [
        "captcha",
        "cloudflare",
        "access denied",
        "security verification",
        "just a moment"
    ]

    lower = text.lower()

    for word in bad_words:
        if word in lower:
            return None

    text = text.replace("\n", " ")
    text = " ".join(text.split())

    return text[:2000]