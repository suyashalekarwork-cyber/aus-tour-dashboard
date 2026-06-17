"""Headless smoke test: render every view and assert no exception."""
from streamlit.testing.v1 import AppTest

PAGES = ["Overview", "Product Insights", "Itinerary Builder", "Tagged Data",
         "Board vs Operator Gap"]

at = AppTest.from_file("app.py", default_timeout=180).run()
assert not at.exception, f"Overview crashed: {at.exception}"
print("OK  Overview")

for p in PAGES[1:]:
    at.sidebar.radio[0].set_value(p).run()
    assert not at.exception, f"{p} crashed: {at.exception}"
    print("OK ", p)

# exercise the builder: choose a day-1 product
at.sidebar.radio[0].set_value("Itinerary Builder").run()
if at.multiselect:
    opts = at.multiselect[0].options
    if opts:
        at.multiselect[0].set_value([opts[0]]).run()
        assert not at.exception, f"Builder pick crashed: {at.exception}"
        print("OK  Builder product pick ->", opts[0])

print("\nALL VIEWS RENDER OK")
