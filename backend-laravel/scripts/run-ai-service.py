import os
import sys
from pathlib import Path

import uvicorn

root = Path(__file__).resolve().parents[2] / "ai-service-python"
sys.path.insert(0, str(root))
os.chdir(root)
port = int(os.getenv("AI_SERVICE_PORT", "9000"))
uvicorn.run("app.main:app", host="127.0.0.1", port=port)
