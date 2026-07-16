import os
import sys
from pathlib import Path

import uvicorn

root = Path(__file__).resolve().parents[2] / "ai-service-python"
sys.path.insert(0, str(root))
os.chdir(root)
uvicorn.run("app.main:app", host="127.0.0.1", port=9000)
