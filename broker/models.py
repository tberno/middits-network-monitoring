
from dataclasses import dataclass, field
from typing import Any, Dict, Optional


@dataclass
class NormalizedAlert:
    source: str
    event_type: str
    state: str
    severity: str
    device: str
    summary: str
    details: str = ""
    ip: Optional[str] = None
    alert_id: Optional[str] = None
    rule: Optional[str] = None
    link: Optional[str] = None
    fired_at: Optional[str] = None
    resolved_at: Optional[str] = None
    downtime: Optional[str] = None
    metadata: Dict[str, Any] = field(default_factory=dict)
