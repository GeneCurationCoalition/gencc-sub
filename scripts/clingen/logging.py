"""
Logging utilities for ClinGen Pipeline

Provides colored terminal output and standardized logging functions.
"""

import sys
from datetime import datetime
from typing import Optional


class Colors:
    """Terminal colors for output"""
    GREEN = '\033[0;32m'
    YELLOW = '\033[1;33m'
    RED = '\033[0;31m'
    BLUE = '\033[0;34m'
    CYAN = '\033[0;36m'
    BOLD = '\033[1m'
    NC = '\033[0m'  # No Color


class Logger:
    """
    Centralized logger for the ClinGen pipeline.

    Provides consistent colored output across all modules.
    """

    def __init__(self, use_stderr: bool = True, enable_colors: bool = True):
        """
        Initialize logger.

        Args:
            use_stderr: If True, log to stderr (default). If False, log to stdout.
            enable_colors: If True, use colored output. If False, plain text.
        """
        self.output = sys.stderr if use_stderr else sys.stdout
        self.enable_colors = enable_colors
        self._step_count = 0
        self._total_steps = 0

    def _color(self, color: str) -> str:
        """Return color code if colors enabled, empty string otherwise"""
        return color if self.enable_colors else ''

    def info(self, message: str) -> None:
        """Log an info message"""
        print(f"{self._color(Colors.GREEN)}[INFO]{self._color(Colors.NC)} {message}", file=self.output)

    def warn(self, message: str) -> None:
        """Log a warning message"""
        print(f"{self._color(Colors.YELLOW)}[WARN]{self._color(Colors.NC)} {message}", file=self.output)

    def error(self, message: str) -> None:
        """Log an error message"""
        print(f"{self._color(Colors.RED)}[ERROR]{self._color(Colors.NC)} {message}", file=self.output)

    def success(self, message: str) -> None:
        """Log a success message with checkmark"""
        print(f"{self._color(Colors.GREEN)}[INFO]{self._color(Colors.NC)} ✓ {message}", file=self.output)

    def failure(self, message: str) -> None:
        """Log a failure message with X"""
        print(f"{self._color(Colors.RED)}[ERROR]{self._color(Colors.NC)} ✗ {message}", file=self.output)

    def section(self, message: str) -> None:
        """Log a section header"""
        print(f"\n{self._color(Colors.BLUE)}{self._color(Colors.BOLD)}{'=' * 80}", file=self.output)
        print(f"{message}", file=self.output)
        print(f"{'=' * 80}{self._color(Colors.NC)}\n", file=self.output)

    def step(self, step_num: int, total_steps: int, message: str) -> None:
        """Log a step in a multi-step process"""
        self._step_count = step_num
        self._total_steps = total_steps
        print(f"{self._color(Colors.CYAN)}[STEP {step_num}/{total_steps}]{self._color(Colors.NC)} {message}", file=self.output)

    def substep(self, message: str) -> None:
        """Log a substep (indented info)"""
        print(f"  {self._color(Colors.GREEN)}→{self._color(Colors.NC)} {message}", file=self.output)

    def progress(self, current: int, total: int, prefix: str = "Progress") -> None:
        """Log progress (for batch processing)"""
        if current % 100 == 0 or current == total:
            pct = (current / total) * 100 if total > 0 else 0
            print(f"{self._color(Colors.CYAN)}[{prefix}]{self._color(Colors.NC)} {current}/{total} ({pct:.1f}%)", file=self.output)

    def stats(self, label: str, value: int, indent: int = 0) -> None:
        """Log a statistic"""
        indent_str = "  " * indent
        print(f"{indent_str}{label}: {value}", file=self.output)

    def blank(self) -> None:
        """Print a blank line"""
        print("", file=self.output)


# Global logger instance
_logger: Optional[Logger] = None


def get_logger() -> Logger:
    """Get the global logger instance"""
    global _logger
    if _logger is None:
        _logger = Logger()
    return _logger


def set_logger(logger: Logger) -> None:
    """Set the global logger instance"""
    global _logger
    _logger = logger


# Convenience functions that use the global logger
def log_info(message: str) -> None:
    """Log an info message"""
    get_logger().info(message)


def log_warn(message: str) -> None:
    """Log a warning message"""
    get_logger().warn(message)


def log_error(message: str) -> None:
    """Log an error message"""
    get_logger().error(message)


def log_success(message: str) -> None:
    """Log a success message"""
    get_logger().success(message)


def log_failure(message: str) -> None:
    """Log a failure message"""
    get_logger().failure(message)


def log_section(message: str) -> None:
    """Log a section header"""
    get_logger().section(message)


def log_step(step_num: int, total_steps: int, message: str) -> None:
    """Log a step"""
    get_logger().step(step_num, total_steps, message)


def log_substep(message: str) -> None:
    """Log a substep"""
    get_logger().substep(message)
