# LLM Directory Formatter 🤖

Effortlessly package your entire codebase for analysis by Large Language Models. This command-line tool recursively reads a directory, respects your `.gitignore` rules, and formats the contents into a single, clean text block that you can paste directly into prompts for models like GPT-5, Claude, or Gemini.

### Demo

Imagine this project structure:

```sh
.
├── .gitignore
├── index.php
├── assets/
│   ├── css/
│   │   └── style.css
│   └── js/
│       └── app.js
└── vendor/
    └── some-library/
        └── file.php
```

Running the tool is as simple as:

```sh
$ php llm-format.php --use-gitignore --copy

Skipping binary file: assets/logo.png
Formatted content has been copied to the clipboard.
```

Now, your clipboard contains the formatted contents of `index.php`, `assets/css/style.css`, and `assets/js/app.js`, ready for your LLM. The `vendor` directory and the binary `logo.png` were automatically ignored.

-----

## Features

  * **📁 Recursive Scanning**: Traverses your entire project directory structure automatically.
  * **🚫 Smart Ignoring**:
      * Natively supports `.gitignore` rules to exclude irrelevant files and directories.
      * Allows for additional custom ignore patterns via the command line (e.g., `dist/*`, `*.log`).
  * **🧩 MIME Type Detection**: Includes the MIME type for each file, giving the LLM better context.
  * **💨 Skips Binaries**: Intelligently detects and skips binary files (like images, executables, etc.) to keep the output clean.
  * **📋 Clipboard Integration**: Uses the powerful OSC 52 escape sequence to copy the output directly to your system clipboard, even over an SSH connection\!
  * **💻 Cross-Platform**: Written in PHP, it runs anywhere you have the PHP CLI and standard command-line tools.

-----

## The Problem It Solves

When you need an LLM to understand or work with a multi-file project, you face several challenges:

1.  **Manual Labor**: Opening each file, copying its content, and pasting it into the prompt is tedious and error-prone.
2.  **Loss of Context**: You have to manually add file paths to tell the LLM which code belongs to which file.
3.  **Clutter**: You might accidentally include build artifacts, dependencies (`node_modules`, `vendor`), or local configuration files (`.env`), which wastes tokens and confuses the model.

This tool solves all three problems by creating a single, perfectly formatted text block representing your project's context in seconds.

-----

## Requirements

  * **PHP** (version 7.4 or newer is recommended).
  * **`file` command-line utility**: This tool is used for reliable binary file detection. It's pre-installed on virtually all Linux and macOS systems. For Windows users, it's available through [Git for Windows](https://git-scm.com/download/win) (included in Git Bash) or WSL.

-----

## Installation

1.  **Clone the repository:**

    ```sh
    git clone https://github.com/arthurdick/llm-formatter.git
    cd llm-formatter
    ```

2.  **Make the script executable:**

    ```sh
    chmod +x llm-format.php
    ```

3.  **(Recommended) Add to your PATH:**
    For easy access from anywhere, move the script to a directory in your system's `PATH` or add its location to your shell's configuration file (e.g., `~/.bashrc`, `~/.zshrc`).

    ```sh
    # Example: move to /usr/local/bin
    sudo mv llm-format.php /usr/local/bin/llm-format

    # Now you can run it from any directory
    llm-format --help
    ```

-----

## Usage

The script is controlled via command-line options.

```
php llm-format.php [options]
```

### Options

| Short | Long              | Description                                                                 | Default            |
| :---- | :---------------- | :-------------------------------------------------------------------------- | :----------------- |
| `-d`  | `--dir <path>`    | The directory to process.                                                   | Current directory  |
| `-g`  | `--use-gitignore` | Exclude files and directories specified by found `.gitignore` files.        | Disabled           |
| `-i`  | `--ignore <csv>`  | A comma-separated list of glob patterns to ignore (e.g., `"*.log,build/*"`). | None               |
| `-c`  | `--copy`          | Copy the output to the system clipboard instead of printing to the terminal. | Disabled           |
| `-h`  | `--help`          | Display the help message.                                                   | -                  |

### Examples

**1. Process the current directory and print to terminal:**

```sh
php llm-format.php
```

**2. Process a specific directory, respecting `.gitignore`, and copy to clipboard:**

```sh
php llm-format.php -d ./my-project -g -c
```

**3. Process the current directory, ignoring log files and the `dist` folder:**

```sh
php llm-format.php --ignore "*.log,dist/*"
```

-----

## Example Output

The generated output is formatted for clarity, with clear separators for each file.

```text
--- BEGIN FILE: src/index.php (MIME: text/x-php) ---
<?php
require_once 'helpers.php';

echo render_page("Welcome!");
--- END FILE: src/index.php ---

--- BEGIN FILE: src/helpers.php (MIME: text/x-php) ---
<?php
function render_page(string $title): string {
    return "<html><head><title>{$title}</title></head></html>";
}
--- END FILE: src/helpers.php ---
```

-----

## A Note on Clipboard (`--copy`)

The `--copy` feature uses the **OSC 52** terminal escape sequence. This is a modern and secure way to access the system clipboard that works seamlessly, even over remote SSH sessions.

For it to work, you must be using a compatible terminal emulator, such as:

  * iTerm2
  * Kitty
  * WezTerm
  * Windows Terminal
  * Alacritty

Older terminals may not support this feature. If `--copy` doesn't work, you can always pipe the output to your system's clipboard command:

```sh
# macOS
php llm-format.php | pbcopy

# Linux (requires xclip)
php llm-format.php | xclip -selection clipboard
```

-----

## License

This project is licensed under the MIT License. See the LICENSE file for details.
