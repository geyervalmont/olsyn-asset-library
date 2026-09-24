"""Small, bounded presentation helpers shared by the Kit browser and its tests."""
import uuid


def page_numbers(current, last):
    pages = sorted({1, last, *range(max(1, current - 2), min(last, current + 2) + 1)})
    result = []
    for page in pages:
        if result and page - result[-1] > 1:
            result.append(None)
        result.append(page)
    return result


def preview_name(item):
    return f'{uuid.UUID(item["uuid"])}-v{int(item["material_version"])}.jpg'
