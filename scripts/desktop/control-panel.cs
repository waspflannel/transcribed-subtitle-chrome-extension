using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.Text;
using System.Web.Script.Serialization;
using System.Windows.Forms;

internal sealed class ControlPanel : Form
{
    private readonly string packageDirectory = AppDomain.CurrentDomain.BaseDirectory;
    private readonly string dataDirectory;
    private readonly string guideUrl;
    private readonly Label status;
    private readonly Label detail;
    private readonly Button setup;
    private readonly Button start;
    private readonly Button extension;
    private readonly Timer timer = new Timer();
    private readonly object outputLock = new object();
    private readonly StringBuilder output = new StringBuilder();
    private readonly StringBuilder errors = new StringBuilder();
    private readonly Color bone = ColorTranslator.FromHtml("#eee9df");
    private readonly Color muted = ColorTranslator.FromHtml("#aaa69d");
    private readonly Color crimson = ColorTranslator.FromHtml("#d83b3b");
    private Process operation;
    private string action;
    private string pendingAction;
    private volatile string progress;
    private DateTime lastCheck = DateTime.MinValue;
    private bool setupComplete;
    private bool running;
    private bool healthy;
    private bool needsAttention;

    private ControlPanel(string dataPath)
    {
        dataDirectory = dataPath;
        Directory.CreateDirectory(dataDirectory);
        var manifest = new JavaScriptSerializer().Deserialize<Dictionary<string, object>>(
            File.ReadAllText(Path.Combine(packageDirectory, "package.json")));
        guideUrl = (string)manifest["guideUrl"];
        var guide = new Uri(guideUrl);
        if (guide.Scheme != "https" && !(guide.Scheme == "http" && guide.Host == "127.0.0.1"))
            throw new InvalidOperationException("Invalid guide address.");

        Text = "Transcribe";
        ClientSize = new Size(660, 530);
        FormBorderStyle = FormBorderStyle.FixedSingle;
        MaximizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;
        BackColor = ColorTranslator.FromHtml("#111111");
        ForeColor = bone;
        Font = new Font("Segoe UI", 10);
        AutoScaleMode = AutoScaleMode.Dpi;

        var title = AddLabel("Transcribe", 30, 20, 590, 48, 28);
        title.Font = new Font("Segoe UI", 28, FontStyle.Bold);
        AddLabel("Your local subtitle backend", 32, 75, 580, 24, 10).ForeColor = muted;
        status = AddLabel("Checking setup...", 32, 120, 590, 28, 14);
        detail = AddLabel("", 32, 156, 590, 50, 10);
        detail.ForeColor = muted;
        setup = AddAction("1. Install all requirements", "Set up Docker Desktop and the bundled runtime.", 224, false);
        start = AddAction("2. Start backend", "Run the backend, workers and scheduler.", 294, true);
        extension = AddAction("3. Install browser extension", "Open the how-to-use guide in your browser.", 364, false);
        AddLabel("Add keys in extension Settings. Closing this panel leaves the backend running.", 32, 435, 580, 40, 10).ForeColor = muted;
        var log = new LinkLabel { Text = "Open setup log", Location = new Point(32, 478), Size = new Size(160, 24), LinkColor = bone, ActiveLinkColor = crimson };
        log.LinkClicked += delegate {
            string path = Path.Combine(dataDirectory, "setup.log");
            if (!File.Exists(path)) File.WriteAllText(path, "No setup operations yet.");
            Process.Start("notepad.exe", Quote(path));
        };
        Controls.Add(log);
        var version = AddLabel("Windows x64 / " + manifest["version"], 395, 478, 225, 24, 10);
        version.ForeColor = muted;
        version.TextAlign = ContentAlignment.MiddleRight;

        setup.Click += delegate { StartOperation("Setup"); };
        start.Click += delegate { StartOperation(running ? "Stop" : "Start"); };
        extension.Click += delegate { Process.Start(new ProcessStartInfo(guideUrl) { UseShellExecute = true }); };
        FormClosing += delegate(object sender, FormClosingEventArgs e) {
            if (operation != null && action != "Status") {
                e.Cancel = true;
                detail.Text = "Wait for the current operation to finish before closing the panel.";
            }
        };
        FormClosed += delegate { timer.Stop(); timer.Dispose(); };
        timer.Interval = 500;
        timer.Tick += Tick;
        timer.Start();
    }

    private Label AddLabel(string text, int x, int y, int width, int height, int size)
    {
        var label = new Label { Text = text, Location = new Point(x, y), Size = new Size(width, height), Font = new Font("Segoe UI", size) };
        Controls.Add(label);
        return label;
    }

    private sealed class ActionButton : Button
    {
        protected override void OnPaint(PaintEventArgs e)
        {
            base.OnPaint(e);
            if (Enabled) return;
            e.Graphics.Clear(Color.FromArgb(24, 24, 24));
            using (var pen = new Pen(Color.FromArgb(80, 80, 80)))
                e.Graphics.DrawRectangle(pen, 0, 0, Width - 1, Height - 1);
            TextRenderer.DrawText(e.Graphics, Text, Font, ClientRectangle, Color.FromArgb(170, 166, 157),
                TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter);
        }
    }

    private Button AddAction(string text, string description, int y, bool primary)
    {
        var button = new ActionButton {
            Text = text, AccessibleName = text, Location = new Point(32, y), Size = new Size(270, 48),
            FlatStyle = FlatStyle.Flat, BackColor = primary ? crimson : BackColor, ForeColor = primary ? Color.White : bone,
            Font = new Font("Segoe UI", 10, FontStyle.Bold), Cursor = Cursors.Hand, Enabled = false
        };
        button.FlatAppearance.BorderColor = muted;
        Controls.Add(button);
        AddLabel(description, 320, y + 4, 300, 44, 10).ForeColor = muted;
        return button;
    }

    private static string Quote(string value) { return "\"" + value + "\""; }

    private void UpdateButtons()
    {
        bool busy = (operation != null && action != "Status") || pendingAction != null;
        setup.Enabled = !busy && !running;
        setup.Text = setupComplete ? "1. Requirements installed" : "1. Install all requirements";
        start.Enabled = !busy && (setupComplete || running);
        start.Text = running ? "2. Stop backend" : "2. Start backend";
        extension.Enabled = !busy && (healthy || guideUrl.StartsWith("https://"));
    }

    private void StartOperation(string requestedAction)
    {
        if (operation != null) {
            if (action == "Status" && requestedAction != "Status") {
                pendingAction = requestedAction;
                UpdateButtons();
            }
            return;
        }
        action = requestedAction;
        progress = null;
        lock (outputLock) { output.Clear(); errors.Clear(); }
        if (action != "Status") {
            needsAttention = false;
            status.Text = action == "Setup" ? "Setting up..." : action == "Start" ? "Starting..." : "Stopping...";
        }
        try {
            var info = new ProcessStartInfo {
                FileName = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "WindowsPowerShell", "v1.0", "powershell.exe"),
                Arguments = "-NoProfile -ExecutionPolicy Bypass -File " + Quote(Path.Combine(packageDirectory, "runtime.ps1"))
                    + " -Action " + action + " -PackageDirectory " + Quote(packageDirectory.TrimEnd('\\')) + " -DataDirectory " + Quote(dataDirectory),
                UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true
            };
            operation = new Process { StartInfo = info };
            operation.OutputDataReceived += delegate(object sender, DataReceivedEventArgs e) {
                if (e.Data == null) return;
                lock (outputLock) output.AppendLine(e.Data);
                if (e.Data.StartsWith("TRANSCRIBE: ")) progress = e.Data.Substring(12);
            };
            operation.ErrorDataReceived += delegate(object sender, DataReceivedEventArgs e) {
                if (e.Data != null) lock (outputLock) errors.AppendLine(e.Data);
            };
            operation.Start();
            operation.BeginOutputReadLine();
            operation.BeginErrorReadLine();
        } catch (Exception) {
            if (operation != null) operation.Dispose();
            operation = null;
            needsAttention = true;
            status.Text = "Needs attention";
            detail.Text = "Windows could not start setup. Extract the entire download into a local folder and try again.";
            lastCheck = DateTime.UtcNow;
        }
        UpdateButtons();
    }

    private void Tick(object sender, EventArgs e)
    {
        if (operation == null) {
            if ((DateTime.UtcNow - lastCheck).TotalSeconds >= 10) StartOperation("Status");
            return;
        }
        if (progress != null) detail.Text = progress;
        if (!operation.HasExited) return;
        operation.WaitForExit();
        int exitCode = operation.ExitCode;
        operation.Dispose();
        operation = null;
        string text;
        string errorText;
        lock (outputLock) { text = output.ToString(); errorText = errors.ToString(); }
        if (action == "Status") {
            lastCheck = DateTime.UtcNow;
            try {
                if (exitCode != 0) throw new InvalidOperationException();
                var state = new JavaScriptSerializer().Deserialize<Dictionary<string, bool>>(text);
                setupComplete = state["setupComplete"];
                running = state["running"];
                healthy = state["healthy"];
                if (!needsAttention) {
                    status.Text = healthy ? "Ready" : running ? "Backend needs attention" : setupComplete ? "Backend stopped" : "Setup needed";
                    detail.Text = healthy ? "Everything is running. Open the extension guide to get started."
                        : running ? "A service is not ready. Open the setup log or stop and start the backend."
                        : setupComplete ? "Select Start backend. Your saved data and keys are preserved."
                        : "Select Install all requirements to prepare your computer.";
                }
            } catch (Exception) {
                status.Text = "Needs attention";
                detail.Text = "Setup status could not be checked. Extract the entire download and try again.";
            }
            if (pendingAction != null) {
                string requestedAction = pendingAction;
                pendingAction = null;
                StartOperation(requestedAction);
            }
        } else {
            File.AppendAllText(Path.Combine(dataDirectory, "setup.log"), DateTime.Now.ToString("s") + " / " + action + Environment.NewLine + text + errorText);
            if (exitCode != 0) {
                needsAttention = true;
                status.Text = "Needs attention";
                detail.Text = "The operation could not finish. Open the setup log for the error and next steps.";
                MessageBox.Show(this, errorText, "Transcribe needs attention", MessageBoxButtons.OK, MessageBoxIcon.Error);
            }
            StartOperation("Status");
        }
        UpdateButtons();
    }

    [STAThread]
    private static void Main(string[] args)
    {
        Application.EnableVisualStyles();
        try {
            string data = args.Length == 1 ? Path.GetFullPath(args[0]) : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Transcribe");
            Application.Run(new ControlPanel(data));
        } catch (Exception) {
            MessageBox.Show("Extract the entire Transcribe download into a local folder before opening the control panel.", "Transcribe");
        }
    }
}
